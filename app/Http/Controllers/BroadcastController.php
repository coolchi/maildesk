<?php

namespace App\Http\Controllers;

use App\Models\Broadcast;
use App\Services\BroadcastService;
use App\Support\CurrentOrganization;
use App\Support\DesignTemplates;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BroadcastController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = CurrentOrganization::from($request);

        $broadcasts = $organization->broadcasts()
            ->latest()
            ->get()
            ->map(fn (Broadcast $broadcast) => $broadcast->toWorkspaceArray())
            ->values()
            ->all();

        return Inertia::render('Broadcasts/Index', [
            'broadcasts' => $broadcasts,
        ]);
    }

    public function create(Request $request): Response
    {
        $organization = CurrentOrganization::from($request);

        $segments = $organization->segments()
            ->withCount('contacts')
            ->orderBy('name')
            ->get()
            ->map(fn ($segment) => [
                'id' => $segment->id,
                'label' => $segment->name,
                'count' => $segment->contacts_count,
            ])
            ->values()
            ->all();

        $groups = $organization->groupAddresses()
            ->where('active', true)
            ->withCount('members')
            ->orderBy('name')
            ->get()
            ->map(fn ($group) => [
                'id' => 'group:'.$group->id,
                'label' => "Group: {$group->name} ({$group->email})",
                'count' => $group->members_count,
            ])
            ->values()
            ->all();

        return Inertia::render('Broadcasts/Create', [
            'segments' => array_merge(
                [['id' => 'all', 'label' => 'All subscribed contacts', 'count' => $organization->contacts()->count()]],
                $segments,
                $groups,
            ),
            'selected' => $request->query('segment'),
        ]);
    }

    public function store(Request $request, BroadcastService $broadcasts): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'subject' => ['required_without:source_id', 'nullable', 'string', 'max:255'],
            'html' => ['required_without:source_id', 'nullable', 'string'],
            'design_key' => ['nullable', 'string', 'max:40'],
            'segment' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'string', 'max:255'],
            'send_now' => ['sometimes', 'boolean'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'source_id' => ['nullable', 'integer'],
        ]);

        if (! empty($validated['source_id'])) {
            $source = $organization->broadcasts()->whereKey($validated['source_id'])->firstOrFail();
            $broadcast = Broadcast::query()->create([
                'organization_id' => $organization->id,
                'name' => ($validated['name'] ?: $source->name).' (copy)',
                'subject' => $source->subject,
                'html' => $source->html,
                'design_key' => $source->design_key,
                'audience' => $source->audience,
                'from' => $source->from,
                'status' => 'draft',
            ]);

            return redirect()->route('broadcasts.show', $broadcast)->with('success', 'Broadcast duplicated.');
        }

        $audience = $validated['segment'] ?? 'all';
        if (str_starts_with($audience, 'group:')
            && ! $organization->groupAddresses()->whereKey((int) substr($audience, 6))->exists()) {
            return back()->withErrors(['segment' => 'That group address does not exist in this workspace.']);
        }

        $sendNow = $request->boolean('send_now', true);
        $scheduledAt = isset($validated['scheduled_at'])
            ? Carbon::parse($validated['scheduled_at'])
            : null;

        if (! $sendNow && $scheduledAt !== null) {
            $status = 'scheduled';
        } elseif ($sendNow) {
            $status = 'draft';
        } else {
            $status = 'draft';
            $scheduledAt = null;
        }

        $broadcast = Broadcast::query()->create([
            'organization_id' => $organization->id,
            'name' => $validated['name'],
            'subject' => $validated['subject'],
            'html' => $validated['html'],
            'design_key' => DesignTemplates::normalize($validated['design_key'] ?? null),
            'audience' => $audience,
            'from' => $validated['from'] ?? null,
            'status' => $status,
            'scheduled_at' => $status === 'scheduled' ? $scheduledAt : null,
        ]);

        if ($sendNow) {
            $broadcasts->queue($broadcast);

            return redirect()->route('broadcasts.show', $broadcast)->with('success', 'Broadcast queued for sending.');
        }

        if ($status === 'scheduled') {
            return redirect()->route('broadcasts.show', $broadcast)->with('success', 'Broadcast scheduled.');
        }

        return redirect()->route('broadcasts.show', $broadcast)->with('success', 'Draft saved.');
    }

    public function send(Request $request, Broadcast $broadcast, BroadcastService $broadcasts): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($broadcast->organization_id === $organization->id, 404);

        if (! in_array($broadcast->status, ['draft', 'scheduled'], true)) {
            return back()->with('error', 'Only draft or scheduled broadcasts can be sent.');
        }

        if (blank($broadcast->subject) || blank($broadcast->html)) {
            return back()->with('error', 'Add a subject and content before sending.');
        }

        $broadcasts->queue($broadcast);

        return back()->with('success', 'Broadcast queued for sending.');
    }

    public function cancel(Request $request, Broadcast $broadcast): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($broadcast->organization_id === $organization->id, 404);

        if ($broadcast->status !== 'scheduled') {
            return back()->with('error', 'Only scheduled broadcasts can be cancelled.');
        }

        $broadcast->forceFill([
            'status' => 'draft',
            'scheduled_at' => null,
        ])->save();

        return back()->with('success', 'Schedule cancelled. Broadcast is a draft again.');
    }

    public function show(Request $request, Broadcast $broadcast, BroadcastService $broadcasts): Response
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($broadcast->organization_id === $organization->id, 404);

        return Inertia::render('Broadcasts/Show', [
            'id' => $broadcast->id,
            'broadcast' => $broadcast->toWorkspaceArray() + [
                'html' => $broadcast->html,
                'subject' => $broadcast->subject,
                'from' => $broadcast->from,
            ],
            'counts' => $broadcasts->counts($broadcast),
            'recipients' => $broadcast->recipients()
                ->with('message:id,status')
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn ($recipient) => [
                    'id' => $recipient->id,
                    'email' => $recipient->email,
                    'status' => $recipient->unsubscribed_at
                        ? 'unsubscribed'
                        : (in_array($recipient->message?->status, ['delivered', 'bounced', 'complained'], true)
                            ? $recipient->message->status
                            : $recipient->status),
                    'error' => $recipient->error,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function destroy(Request $request, Broadcast $broadcast): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($broadcast->organization_id === $organization->id, 404);

        $broadcast->delete();

        return redirect()->route('broadcasts')->with('success', 'Broadcast deleted.');
    }
}
