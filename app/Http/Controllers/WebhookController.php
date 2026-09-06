<?php

namespace App\Http\Controllers;

use App\Models\Webhook;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WebhookController extends Controller
{
    private const EVENT_OPTIONS = [
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
            'url' => ['required', 'url', 'max:500', 'starts_with:http://,https://'],
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
            'webhook' => $webhook->toWorkspaceArray(includeSecret: true),
            'deliveries' => $deliveries,
            'eventOptions' => self::EVENT_OPTIONS,
            'plainWebhookSecret' => $request->session()->get('plain_webhook_secret'),
        ]);
    }

    public function update(Request $request, Webhook $webhook): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($webhook->organization_id === $organization->id, 404);

        $validated = $request->validate([
            'url' => ['sometimes', 'url', 'max:500', 'starts_with:http://,https://'],
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
