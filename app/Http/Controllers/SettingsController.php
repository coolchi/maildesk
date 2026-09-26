<?php

namespace App\Http\Controllers;

use App\Mail\MailManager;
use App\Models\Organization;
use App\Models\ProviderConfig;
use App\Services\Billing\BillingService;
use App\Services\SignatureService;
use App\Support\CurrentOrganization;
use App\Support\EmailHtmlSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function show(Request $request, string $tab = 'usage'): Response
    {
        $organization = CurrentOrganization::from($request);
        $organization->loadMissing(['subscription.plan', 'mailProvider']);

        $settings = $organization->settings ?? [];
        $plan = $organization->subscription?->plan;
        $emailsLimit = $plan?->emails ?? 50000;
        $contactsLimit = $plan?->contacts ?? 1000;
        $seatsLimit = $plan?->seats ?? ($organization->seats ?: 10);

        $emailsUsed = $organization->messages()
            ->where('direction', 'outbound')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $usage = [
            'transactional' => [
                'plan' => $organization->plan ?: 'Free',
                'monthly' => [
                    'used' => $emailsUsed,
                    'limit' => $emailsLimit ?: 50000,
                    'renews' => $organization->subscription?->renews_at
                        ?? now()->addMonth()->startOfMonth()->format('M j, Y'),
                ],
                'daily' => 'Unlimited',
            ],
            'marketing' => [
                'plan' => $organization->product === 'marketing' ? ($organization->plan ?: 'Free') : 'Free',
                'contacts' => [
                    'used' => method_exists($organization, 'contacts')
                        ? $organization->contacts()->count()
                        : 0,
                    'limit' => $contactsLimit ?: 1000,
                ],
                'segments' => [
                    'used' => method_exists($organization, 'segments')
                        ? $organization->segments()->count()
                        : 0,
                    'limit' => 3,
                ],
                'broadcasts' => 'Unlimited',
            ],
            'team' => [
                'plan' => $organization->plan ?: 'Free',
                'seats' => [
                    'used' => $organization->users()->count(),
                    'limit' => $seatsLimit ?: 10,
                ],
            ],
        ];

        $billing = [
            'email' => $organization->owner_email,
            'fullName' => $organization->owner_name,
            'subscriptions' => $organization->subscriptions()
                ->with('plan')
                ->latest('id')
                ->get()
                ->map(fn ($sub) => [
                    'id' => $sub->key ?? (string) $sub->id,
                    'name' => ucfirst((string) $sub->product).' · '.$sub->plan_name,
                    'renews' => $sub->renews_at ? 'Renews '.$sub->renews_at : null,
                    'quota' => $sub->plan?->emails
                        ? number_format($sub->plan->emails).' emails'
                        : ($sub->plan?->contacts
                            ? number_format($sub->plan->contacts).' contacts'
                            : '—'),
                    'price' => '$'.number_format((int) $sub->price, 2).' / mo',
                    'status' => $sub->status,
                ])
                ->values()
                ->all(),
        ];

        return Inertia::render('Settings/Index', [
            'tab' => $tab,
            'usage' => $usage,
            'billing' => $billing,
            // Monipay plan upgrades + payment history (no secret material).
            'payments' => fn () => app(BillingService::class)->settingsProps($request->user(), $organization),
            'settings' => [
                'unsubscribe' => $settings['unsubscribe'] ?? [
                    'brand' => $organization->name,
                    'headline' => "You've been unsubscribed",
                    'message' => "Thanks for letting us know. You won't receive marketing emails from us anymore.",
                    'askReason' => true,
                    'showPreferences' => true,
                    'preferencesUrl' => '',
                    'buttonLabel' => 'Manage preferences',
                    'accent' => '#0891b2',
                    'footer' => 'If this was a mistake, you can update your preferences anytime.',
                ],
                'documents' => $settings['documents'] ?? [],
                'signature' => app(SignatureService::class)->settings($organization),
            ],
            'smtp' => $this->smtpSettings($request, $organization),
            'mailboxes' => $organization->mailboxes()
                ->orderBy('email')
                ->get(['id', 'email', 'display_name', 'signature'])
                ->map(fn ($mailbox) => [
                    'id' => $mailbox->id,
                    'email' => $mailbox->email,
                    'display_name' => $mailbox->display_name,
                    'signature' => (string) ($mailbox->signature ?? ''),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);

        $validated = $request->validate([
            'unsubscribe' => ['sometimes', 'array'],
            'unsubscribe.brand' => ['nullable', 'string', 'max:120'],
            'unsubscribe.headline' => ['nullable', 'string', 'max:255'],
            'unsubscribe.message' => ['nullable', 'string', 'max:2000'],
            'unsubscribe.askReason' => ['sometimes', 'boolean'],
            'unsubscribe.showPreferences' => ['sometimes', 'boolean'],
            'unsubscribe.preferencesUrl' => ['nullable', 'string', 'max:500'],
            'unsubscribe.buttonLabel' => ['nullable', 'string', 'max:120'],
            'unsubscribe.accent' => ['nullable', 'string', 'max:32'],
            'unsubscribe.footer' => ['nullable', 'string', 'max:500'],
            'documents' => ['sometimes', 'array'],
            'signature' => ['sometimes', 'array'],
            'signature.enabled' => ['sometimes', 'boolean'],
            'signature.html' => ['nullable', 'string', 'max:20000'],
            'signature.api' => ['sometimes', 'boolean'],
            'signature.broadcasts' => ['sometimes', 'boolean'],
            'mailbox_signatures' => ['sometimes', 'array'],
            'mailbox_signatures.*.id' => ['required', 'integer'],
            'mailbox_signatures.*.signature' => ['nullable', 'string', 'max:20000'],
        ]);

        $settings = $organization->settings ?? [];

        if (array_key_exists('unsubscribe', $validated)) {
            $settings['unsubscribe'] = array_merge(
                $settings['unsubscribe'] ?? [],
                $validated['unsubscribe'],
            );
        }

        if (array_key_exists('documents', $validated)) {
            $settings['documents'] = $validated['documents'];
        }

        if (array_key_exists('signature', $validated)) {
            $signature = array_merge($settings['signature'] ?? [], $validated['signature']);
            // Stored cleaned so what you see in settings is what recipients get.
            $signature['html'] = (string) (EmailHtmlSanitizer::clean($signature['html'] ?? '') ?? '');
            $settings['signature'] = $signature;
        }

        foreach ($validated['mailbox_signatures'] ?? [] as $row) {
            $html = trim((string) ($row['signature'] ?? ''));
            $organization->mailboxes()->whereKey($row['id'])->update([
                'signature' => trim(strip_tags($html, '<img>')) === '' ? null : EmailHtmlSanitizer::clean($html),
            ]);
        }

        $organization->update(['settings' => $settings]);

        return back()->with('success', 'Settings saved.');
    }

    /**
     * Save this workspace's own SMTP server. The password is write-only: it is
     * stored encrypted (ProviderConfig.credentials uses the encrypted:array cast),
     * a blank value keeps the saved one, and clear_password removes it.
     */
    public function updateSmtp(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);

        abort_unless($this->canManageSmtp($request, $organization), 403, 'Only workspace owners and admins can change SMTP settings.');

        $enabled = $request->boolean('enabled');

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'host' => [Rule::requiredIf($enabled), 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
            'port' => [Rule::requiredIf($enabled), 'nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1000'],
            'clear_password' => ['sometimes', 'boolean'],
            'encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
        ], [
            'host.regex' => 'Enter a host name or IP address, without a scheme or path.',
        ]);

        /** @var ProviderConfig $config */
        $config = $organization->providerConfigs()->firstOrNew(['provider' => 'smtp']);
        $previous = $config->credentials ?? [];

        $password = $previous['password'] ?? null;
        if ($request->boolean('clear_password')) {
            $password = null;
        }
        if (($validated['password'] ?? '') !== '') {
            $password = $validated['password'];
        }

        $config->credentials = [
            'host' => trim((string) ($validated['host'] ?? '')) ?: null,
            'port' => isset($validated['port']) ? (int) $validated['port'] : null,
            'username' => ($validated['username'] ?? '') !== '' ? $validated['username'] : null,
            'password' => $password,
            'encryption' => $validated['encryption'],
        ];
        $config->is_active = $enabled;
        $config->save();

        return back()->with('success', $enabled ? 'SMTP settings saved. This workspace now sends through your SMTP server.' : 'SMTP settings saved.');
    }

    /**
     * SMTP settings for the page. Never includes the password.
     *
     * @return array<string, mixed>
     */
    protected function smtpSettings(Request $request, Organization $organization): array
    {
        /** @var ProviderConfig|null $config */
        $config = $organization->providerConfigs()->where('provider', 'smtp')->first();
        $credentials = $config?->credentials ?? [];

        return [
            'configured' => $config !== null,
            'enabled' => (bool) ($config?->is_active && trim((string) ($credentials['host'] ?? '')) !== ''),
            'host' => (string) ($credentials['host'] ?? ''),
            'port' => $credentials['port'] ?? 587,
            'username' => (string) ($credentials['username'] ?? ''),
            'encryption' => (string) ($credentials['encryption'] ?? 'tls'),
            'has_password' => ($credentials['password'] ?? '') !== '',
            'can_manage' => $this->canManageSmtp($request, $organization),
            'sending_via' => app(MailManager::class)->driverNameFor($organization),
        ];
    }

    protected function canManageSmtp(Request $request, Organization $organization): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        if ($user->isPlatformAdmin()) {
            return true;
        }

        $role = $organization->users()->whereKey($user->id)->first()?->pivot?->role;

        return in_array($role, ['owner', 'admin'], true);
    }
}
