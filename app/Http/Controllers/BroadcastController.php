<?php

namespace App\Http\Controllers;

use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\Suppression;
use App\Services\EmailService;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

        return Inertia::render('Broadcasts/Create', [
            'segments' => array_merge(
                [['id' => 'all', 'label' => 'All subscribed contacts', 'count' => $organization->contacts()->count()]],
                $segments,
            ),
        ]);
    }

    public function store(Request $request, EmailService $emails): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'subject' => ['required_without:source_id', 'nullable', 'string', 'max:255'],
            'html' => ['required_without:source_id', 'nullable', 'string'],
            'segment' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'string', 'max:255'],
            'send_now' => ['sometimes', 'boolean'],
            'source_id' => ['nullable', 'integer'],
        ]);

        if (! empty($validated['source_id'])) {
            $source = $organization->broadcasts()->whereKey($validated['source_id'])->firstOrFail();
            $broadcast = Broadcast::query()->create([
                'organization_id' => $organization->id,
                'name' => ($validated['name'] ?: $source->name).' (copy)',
                'subject' => $source->subject,
                'html' => $source->html,
                'status' => 'draft',
            ]);

            return redirect()->route('broadcasts.show', $broadcast)->with('success', 'Broadcast duplicated.');
        }

        $sendNow = $request->boolean('send_now', true);
        $broadcast = Broadcast::query()->create([
            'organization_id' => $organization->id,
            'name' => $validated['name'],
            'subject' => $validated['subject'],
            'html' => $validated['html'],
            'status' => $sendNow ? 'sending' : 'draft',
        ]);

        if ($sendNow) {
            $this->dispatchBroadcast($organization, $broadcast, $emails, $validated);
        }

        return redirect()->route('broadcasts.show', $broadcast)->with('success', $sendNow ? 'Broadcast sent.' : 'Draft saved.');
    }

    public function show(Request $request, Broadcast $broadcast): Response
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($broadcast->organization_id === $organization->id, 404);

        return Inertia::render('Broadcasts/Show', [
            'id' => $broadcast->id,
            'broadcast' => $broadcast->toWorkspaceArray() + [
                'html' => $broadcast->html,
                'subject' => $broadcast->subject,
            ],
        ]);
    }

    public function destroy(Request $request, Broadcast $broadcast): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($broadcast->organization_id === $organization->id, 404);

        $broadcast->delete();

        return redirect()->route('broadcasts')->with('success', 'Broadcast deleted.');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function dispatchBroadcast($organization, Broadcast $broadcast, EmailService $emails, array $validated): void
    {
        $segment = $validated['segment'] ?? 'all';
        $query = $organization->contacts()->orderBy('id');

        if ($segment !== 'all' && ctype_digit((string) $segment)) {
            $query->whereHas('segments', fn ($q) => $q->where('segments.id', (int) $segment));
        }

        $suppressed = Suppression::query()
            ->where('organization_id', $organization->id)
            ->pluck('email')
            ->map(fn (string $email) => Str::lower($email))
            ->all();

        $from = $validated['from'] ?? 'hello@'.$organization->slug.'.test';
        $sent = 0;

        $query->chunkById(100, function ($contacts) use ($organization, $broadcast, $emails, $suppressed, $from, &$sent) {
            /** @var Contact $contact */
            foreach ($contacts as $contact) {
                if (in_array(Str::lower($contact->email), $suppressed, true)) {
                    continue;
                }

                $emails->send($organization, [
                    'from' => $from,
                    'to' => [['email' => $contact->email]],
                    'subject' => $broadcast->subject,
                    'html' => $broadcast->html,
                    'text' => strip_tags($broadcast->html),
                    'tags' => ['broadcast:'.$broadcast->id],
                ]);
                $sent++;
            }
        });

        $broadcast->fill([
            'status' => 'sent',
            'sent_at' => now(),
        ])->save();

        unset($sent);
    }
}
