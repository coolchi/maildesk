<?php

namespace App\Http\Middleware;

use App\Mail\MailManager;
use App\Models\Organization;
use App\Services\AccountAccess;
use App\Services\Billing\BillingService;
use App\Services\Impersonation\ImpersonationService;
use App\Services\PlatformSettings;
use App\Services\TenantResolver;
use App\Services\WorkspaceAccess;
use App\Support\InboxSyncState;
use App\Support\PlansCatalog;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        /** @var Organization|null $organization */
        $organization = $request->attributes->get('organization');
        $hostLocked = (bool) $request->attributes->get('tenant_host_locked', false);

        $workspaces = [];
        if ($user) {
            $query = $user->isPlatformAdmin()
                ? Organization::query()->with('mailProvider')->orderBy('name')
                : $user->organizations()->with('mailProvider')->orderBy('name');

            $workspaces = $query->get()->map->toWorkspaceArray()->values()->all();
        }

        $provider = $organization?->mailProvider;
        $mail = app(MailManager::class);
        $access = app(AccountAccess::class);

        if ($organization) {
            $organization->loadMissing(['subscriptions']);
        }

        $sendingFrom = [];
        if ($organization) {
            $restricted = $user
                ? app(WorkspaceAccess::class)->sendingFromAddresses($user, $organization)
                : null;

            if ($restricted !== null) {
                $sendingFrom = $restricted;
            } else {
                $verifiedDomains = $organization->domains()
                    ->where('status', 'verified')
                    ->orderBy('name')
                    ->pluck('name');

                $sendingFrom = $verifiedDomains
                    ->flatMap(fn (string $name) => [
                        "hello@{$name}",
                        "support@{$name}",
                        "noreply@{$name}",
                    ])
                    ->values()
                    ->all();
            }
        }

        $mailboxId = ($user && $organization)
            ? app(WorkspaceAccess::class)->scopedMailboxId($user, $organization)
            : null;

        return [
            ...parent::share($request),
            'csrf_token' => csrf_token(),
            'auth' => [
                'user' => $user
                    ? [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'is_platform_admin' => $user->isPlatformAdmin(),
                        'preferences' => [
                            'inbox_sound' => $user->prefersInboxSound(),
                        ],
                    ]
                    : null,
                'abilities' => fn () => app(WorkspaceAccess::class)->abilities($user, $organization),
                'mailbox_id' => $mailboxId,
            ],
            'tenant' => [
                'current' => $organization?->toWorkspaceArray(),
                'workspaces' => $workspaces,
                'host_locked' => $hostLocked,
                'base_domain' => app(TenantResolver::class)->baseDomain(),
                // No provider config/credentials are shared with tenant pages.
                'provider' => $provider?->toTenantArray(),
                'smtp' => $provider?->smtpSummary(),
                'can_send' => $organization
                    ? (! $access->requiresPayment($organization)
                        && ! $access->organizationBlocked($organization)
                        && $mail->canSendFor($organization))
                    : false,
                'sending_from' => $sendingFrom,
            ],
            'access' => fn () => $access->sharedAccessState($organization),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'draft_id' => fn () => $request->session()->get('draft_id'),
                'plain_api_key' => fn () => $request->session()->get('plain_api_key'),
                'plain_webhook_secret' => fn () => $request->session()->get('plain_webhook_secret'),
            ],
            'plans' => function () use ($user, $organization) {
                $catalog = PlansCatalog::forModal();
                $billing = app(BillingService::class);

                $catalog['checkout'] = [
                    'configured' => $billing->isConfigured(),
                    'can_manage' => $organization
                        ? $billing->canManageBilling($user, $organization)
                        : false,
                ];

                return $catalog;
            },
            'onboarding' => fn () => $organization ? $this->onboarding($organization) : null,
            'impersonation' => fn () => app(ImpersonationService::class)->sharedState($request),
            'inbox_unread' => fn () => $organization
                ? InboxSyncState::for($organization, $mailboxId)['unread']
                : 0,
            'broadcasting' => fn () => [
                'enabled' => config('broadcasting.default') === 'reverb'
                    && filled(config('broadcasting.connections.reverb.key')),
                'driver' => config('broadcasting.default'),
            ],
            'ai' => fn () => [
                'enabled' => app(PlatformSettings::class)->aiEnabled(),
                'features' => app(PlatformSettings::class)->aiFeatureFlags(),
            ],
        ];
    }

    /**
     * Which "Get started" steps this workspace has actually completed,
     * derived from real data so the checklist stays in sync across devices.
     *
     * @return array{domain: bool, apiKey: bool, send: bool, webhook: bool}
     */
    protected function onboarding(Organization $organization): array
    {
        return [
            'domain' => $organization->domains()->where('status', 'verified')->exists(),
            'apiKey' => $organization->apiKeys()
                ->whereNull('revoked_at')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->exists(),
            'send' => $organization->messages()
                ->where('direction', 'outbound')
                ->whereNotIn('status', ['queued', 'scheduled', 'failed', 'suppressed', 'canceled', 'cancelled'])
                ->exists(),
            'webhook' => $organization->webhooks()->where('is_active', true)->exists(),
        ];
    }
}
