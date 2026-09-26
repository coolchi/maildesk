<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Rules\SafeWebhookUrl;
use App\Services\Impersonation\ImpersonationService;
use App\Services\Webhooks\WebhookDeliverer;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WebhookController extends Controller
{
    public const EVENT_OPTIONS = [
        'email.sent',
        'email.delivered',
        'email.bounced',
        'email.complained',
        'email.received',
        'email.opened',
        'email.clicked',
    ];

    public function index(Request $request): Response
    {
        $organization = CurrentOrganization::from($request);

        $webhooks = $organization->webhooks()
            ->with(['deliveries' => fn ($q) => $q->latest('id')->limit(1)])
            ->latest()
            ->get()
            ->map(fn (Webhook $webhook) => $webhook->toWorkspaceArray())
            ->values()
            ->all();

        return Inertia::render('Webhooks/Index', [
            'webhooks' => $webhooks,
            'eventOptions' => self::EVENT_OPTIONS,
            'plainWebhookSecret' => $request->session()->get('plain_webhook_secret'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);

        $validated = $request->validate([
            'url' => ['required', 'url', 'max:500', new SafeWebhookUrl],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(self::EVENT_OPTIONS)],
        ]);

        $secret = Webhook::generateSecret();

        $webhook = Webhook::query()->create([
            'organization_id' => $organization->id,
            'url' => $validated['url'],
            'secret' => $secret,
            'events' => array_values($validated['events']),
            'is_active' => true,
        ]);

        return redirect()
            ->route('webhooks.show', $webhook)
            ->with('plain_webhook_secret', $secret)
            ->with('success', 'Webhook endpoint added.');
    }

    public function show(Request $request, Webhook $webhook): Response
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($webhook->organization_id === $organization->id, 404);

        $deliveries = $webhook->deliveries()
            ->latest('id')
            ->limit(50)
            ->get()
            ->map->toWorkspaceArray()
            ->values()
            ->all();

        return Inertia::render('Webhooks/Show', [
            // Never reveal the signing secret to an impersonating admin.
            'webhook' => $webhook->toWorkspaceArray(includeSecret: ! app(ImpersonationService::class)->isImpersonating($request)),
            'deliveries' => $deliveries,
            'eventOptions' => self::EVENT_OPTIONS,
            'plainWebhookSecret' => $request->session()->get('plain_webhook_secret'),
            'canManage' => $this->canManage($request, $organization),
        ]);
    }

    public function update(Request $request, Webhook $webhook): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($webhook->organization_id === $organization->id, 404);

        $validated = $request->validate([
            'url' => ['sometimes', 'url', 'max:500', new SafeWebhookUrl],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(self::EVENT_OPTIONS)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('url', $validated)) {
            $webhook->url = $validated['url'];
        }
        if (array_key_exists('events', $validated)) {
            $webhook->events = array_values($validated['events']);
        }
        if (array_key_exists('is_active', $validated)) {
            $webhook->is_active = $validated['is_active'];
        }
        $webhook->save();

        return back()->with('success', 'Webhook updated.');
    }

    /**
     * Send a signed `webhook.test` event through the same SSRF-safe path as
     * real events (one attempt, no retries) and report the result.
     */
    public function test(Request $request, Webhook $webhook, WebhookDeliverer $deliverer): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($webhook->organization_id === $organization->id, 404);

        $delivery = WebhookDelivery::query()->create([
            'webhook_id' => $webhook->id,
            'event' => 'webhook.test',
            'payload' => [
                'event' => 'webhook.test',
                'data' => [
                    'webhook_id' => $webhook->id,
                    'message' => 'This is a test event from MailDesk.',
                ],
                'sent_at' => now()->toIso8601String(),
            ],
            'status' => 'pending',
            'attempts' => 0,
        ]);
        $delivery->setRelation('webhook', $webhook);

        $result = $deliverer->attempt($delivery);

        return $result['ok']
            ? back()->with('success', "Test event delivered (HTTP {$result['status']}).")
            : back()->with('error', 'Test event failed: '.$result['error']);
    }

    /**
     * Replace the signing secret (owners/admins only). The new secret is
     * flashed once; the old one stops working immediately.
     */
    public function rotateSecret(Request $request, Webhook $webhook): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($webhook->organization_id === $organization->id, 404);
        abort_unless($this->canManage($request, $organization), 403, 'Only workspace owners and admins can rotate webhook secrets.');
        abort_if(app(ImpersonationService::class)->isImpersonating($request), 403, 'Secrets cannot be rotated while impersonating.');

        $secret = Webhook::generateSecret();
        $webhook->forceFill(['secret' => $secret])->save();

        return redirect()
            ->route('webhooks.show', $webhook)
            ->with('plain_webhook_secret', $secret)
            ->with('success', 'Signing secret rotated. Update your endpoint; the old secret no longer works.');
    }

    protected function canManage(Request $request, Organization $organization): bool
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

    public function destroy(Request $request, Webhook $webhook): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($webhook->organization_id === $organization->id, 404);

        $webhook->delete();

        return redirect()
            ->route('webhooks')
            ->with('success', 'Webhook deleted.');
    }
}
