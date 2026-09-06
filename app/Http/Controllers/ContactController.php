<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Segment;
use App\Models\Suppression;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = CurrentOrganization::from($request);

        $contacts = $organization->contacts()
            ->latest()
            ->get()
            ->map(fn (Contact $contact) => $contact->toWorkspaceArray())
            ->values()
            ->all();

        $segments = $organization->segments()
            ->withCount('contacts')
            ->latest()
            ->get()
            ->map(fn (Segment $segment) => $segment->toWorkspaceArray())
            ->values()
            ->all();

        return Inertia::render('Audience/Index', [
            'contacts' => $contacts,
            'segments' => $segments,
            'properties' => [],
            'topics' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
        ]);

        Contact::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'email' => Str::lower($validated['email']),
            ],
            [
                'first_name' => $validated['first_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
                'meta' => ['status' => 'subscribed'],
            ],
        );

        return back()->with('success', 'Contact saved.');
    }

    public function destroy(Request $request, Contact $contact): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($contact->organization_id === $organization->id, 404);

        $contact->delete();

        return back()->with('success', 'Contact removed.');
    }

    public function suppress(Request $request, Contact $contact): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($contact->organization_id === $organization->id, 404);

        Suppression::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'email' => Str::lower($contact->email),
            ],
            [
                'reason' => 'Added from audience',
                'source' => 'manual',
            ],
        );

        return back()->with('success', 'Contact added to suppressions.');
    }
}
