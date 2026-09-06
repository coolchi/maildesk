<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MailboxController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = CurrentOrganization::from($request);

        $users = $organization->mailboxes()
            ->latest()
            ->get()
            ->map(fn (Mailbox $mailbox) => $mailbox->toWorkspaceArray())
            ->values()
            ->all();

        $domainOptions = $organization->domains()
            ->where('status', 'verified')
            ->orderBy('name')
            ->pluck('name')
            ->values()
            ->all();

        return Inertia::render('Users/Index', [
            'users' => $users,
            'domainOptions' => $domainOptions,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'local' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9._+-]+$/i'],
            'domain' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(['admin', 'developer', 'staff'])],
            'inbox' => ['boolean'],
            'transactional' => ['boolean'],
            'marketing' => ['boolean'],
            'limit' => ['nullable', 'integer', 'min:0'],
        ]);

        $domain = $organization->domains()
            ->where('status', 'verified')
            ->where('name', strtolower($validated['domain']))
            ->firstOrFail();

        $email = strtolower($validated['local']).'@'.$domain->name;

        if (Mailbox::query()->where('email', $email)->exists()) {
            return back()->withErrors(['local' => 'That mailbox address already exists.']);
        }

        Mailbox::query()->create([
            'organization_id' => $organization->id,
            'domain_id' => $domain->id,
            'email' => $email,
            'display_name' => $validated['name'],
            'type' => 'shared',
            'role' => $validated['role'],
            'status' => 'active',
            'inbox' => $request->boolean('inbox', true),
            'transactional' => $request->boolean('transactional'),
            'marketing' => $request->boolean('marketing'),
            'send_limit' => $validated['limit'] ?? 1000,
        ]);

        return back()->with('success', 'Mailbox user created.');
    }

    public function update(Request $request, Mailbox $mailbox): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($mailbox->organization_id === $organization->id, 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'role' => ['sometimes', Rule::in(['admin', 'developer', 'staff'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'inbox' => ['sometimes', 'boolean'],
            'transactional' => ['sometimes', 'boolean'],
            'marketing' => ['sometimes', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:0'],
        ]);

        $mailbox->fill([
            'display_name' => $validated['name'] ?? $mailbox->display_name,
            'role' => $validated['role'] ?? $mailbox->role,
            'status' => $validated['status'] ?? $mailbox->status,
            'inbox' => array_key_exists('inbox', $validated) ? $validated['inbox'] : $mailbox->inbox,
            'transactional' => array_key_exists('transactional', $validated) ? $validated['transactional'] : $mailbox->transactional,
            'marketing' => array_key_exists('marketing', $validated) ? $validated['marketing'] : $mailbox->marketing,
            'send_limit' => array_key_exists('limit', $validated) ? $validated['limit'] : $mailbox->send_limit,
        ])->save();

        return back()->with('success', 'Mailbox user updated.');
    }

    public function destroy(Request $request, Mailbox $mailbox): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($mailbox->organization_id === $organization->id, 404);

        $mailbox->delete();

        return back()->with('success', 'Mailbox user deleted.');
    }
}
