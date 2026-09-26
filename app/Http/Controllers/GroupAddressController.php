<?php

namespace App\Http\Controllers;

use App\Models\GroupAddress;
use App\Models\GroupAddressMember;
use App\Models\Mailbox;
use App\Models\Organization;
use App\Support\AddressList;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Group addresses: one address (staff@company.com) that reaches every member.
 */
class GroupAddressController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = CurrentOrganization::from($request);

        $groups = $organization->groupAddresses()
            ->with('members')
            ->withCount('members')
            ->orderBy('name')
            ->get()
            ->map(fn (GroupAddress $group) => $group->toWorkspaceArray())
            ->values()
            ->all();

        return Inertia::render('Groups/Index', [
            'groups' => $groups,
            'domains' => $organization->domains()->orderBy('name')->pluck('name')->values()->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->normalizeMembers($request);
        $organization = CurrentOrganization::from($request);
        $validated = $this->validateGroup($request, $organization);

        $group = $organization->groupAddresses()->create($validated);

        $this->addMembers($group, (string) $request->input('members', ''));

        return back()->with('success', "Group {$group->email} created.");
    }

    public function update(Request $request, int $group): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        /** @var GroupAddress $model */
        $model = $organization->groupAddresses()->findOrFail($group);

        $model->update($this->validateGroup($request, $organization, $model));

        return back()->with('success', 'Group saved.');
    }

    public function destroy(Request $request, int $group): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        $organization->groupAddresses()->findOrFail($group)->delete();

        return back()->with('success', 'Group deleted.');
    }

    public function addMember(Request $request, int $group): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        /** @var GroupAddress $model */
        $model = $organization->groupAddresses()->findOrFail($group);
        $this->normalizeMembers($request);

        $request->validate([
            'members' => ['required', 'string', 'max:20000', AddressList::rule(500)],
        ]);

        $added = $this->addMembers($model, (string) $request->input('members'));

        return back()->with('success', $added === 1 ? 'Member added.' : "{$added} members added.");
    }

    public function removeMember(Request $request, int $group, int $member): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        /** @var GroupAddress $model */
        $model = $organization->groupAddresses()->findOrFail($group);

        GroupAddressMember::query()
            ->where('group_address_id', $model->id)
            ->whereKey($member)
            ->firstOrFail()
            ->delete();

        return back()->with('success', 'Member removed.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateGroup(Request $request, Organization $organization, ?GroupAddress $existing = null): array
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'members' => ['nullable', 'string', 'max:20000', AddressList::rule(500)],
        ]);

        $email = Str::lower(trim($validated['email']));
        $domain = Str::after($email, '@');

        $ownsDomain = $organization->domains()->whereRaw('lower(name) = ?', [$domain])->exists();
        if (! $ownsDomain) {
            throw ValidationException::withMessages([
                'email' => "Use an address on one of this workspace's domains (e.g. staff@{$this->exampleDomain($organization)}).",
            ]);
        }

        $taken = GroupAddress::query()
            ->where('email', $email)
            ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['email' => 'That group address already exists.']);
        }

        if (Mailbox::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => 'A mailbox already uses that address.']);
        }

        return [
            'email' => $email,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'active' => $request->boolean('active', $existing?->active ?? true),
        ];
    }

    /**
     * Add members from a comma/newline separated list ("Ada <ada@x.com>, bob@y.com").
     */
    protected function addMembers(GroupAddress $group, string $list): int
    {
        $added = 0;

        $items = preg_split('/[,;\n]+(?=(?:[^"]*"[^"]*")*[^"]*$)/', str_replace("\r", '', $list)) ?: [];

        foreach ($items as $item) {
            $name = null;
            $email = trim($item);
            if (preg_match('/^(.*)<(.+)>$/', $email, $m)) {
                $name = trim($m[1], " \t\"") ?: null;
                $email = trim($m[2]);
            }
            $email = Str::lower($email);

            // No nesting and no self-reference.
            if ($email === '' || $email === $group->email || GroupAddress::query()->where('email', $email)->exists()) {
                continue;
            }

            $member = GroupAddressMember::query()->firstOrCreate(
                ['group_address_id' => $group->id, 'email' => $email],
                ['name' => $name],
            );
            $added += $member->wasRecentlyCreated ? 1 : 0;
        }

        return $added;
    }

    protected function normalizeMembers(Request $request): void
    {
        if ($request->filled('members')) {
            $request->merge(['members' => preg_replace('/\s*[\r\n]+\s*/', ', ', trim((string) $request->input('members')))]);
        }
    }

    protected function exampleDomain(Organization $organization): string
    {
        return (string) ($organization->domains()->value('name') ?? 'yourdomain.com');
    }
}
