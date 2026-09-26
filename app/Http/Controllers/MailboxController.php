<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Models\User;
use App\Services\WorkspaceAccess;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class MailboxController extends Controller
{
    public function __construct(public WorkspaceAccess $access) {}

    public function index(Request $request): Response
    {
        $organization = CurrentOrganization::from($request);
        $actor = $request->user();
        abort_unless($this->access->isTeam($actor, $organization), 403);

        $isTeam = true;

        $users = $organization->mailboxes()
            ->latest()
            ->get()
            ->map(fn (Mailbox $mailbox) => $mailbox->toWorkspaceArray($actor, $isTeam))
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
            'canImpersonateTeam' => true,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($this->access->isTeam($request->user(), $organization), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'local' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9._+-]+$/i'],
            'domain' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(['admin', 'developer', 'staff'])],
            'inbox' => ['boolean'],
            'transactional' => ['boolean'],
            'marketing' => ['boolean'],
            'limit' => ['nullable', 'integer', 'min:0'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $domain = $organization->domains()
            ->where('status', 'verified')
            ->where('name', strtolower($validated['domain']))
            ->firstOrFail();

        $email = strtolower($validated['local']).'@'.$domain->name;

        if (Mailbox::query()->where('email', $email)->exists()) {
            return back()->withErrors(['local' => 'That mailbox address already exists.']);
        }

        if (User::query()->where('email', $email)->exists()) {
            return back()->withErrors(['local' => 'That email is already registered as a login.']);
        }

        $inbox = $request->boolean('inbox', true);
        $transactional = $request->boolean('transactional');
        $marketing = $request->boolean('marketing');

        if (! $inbox && ! $transactional && ! $marketing) {
            return back()->withErrors(['inbox' => 'Assign at least inbox, transactional, or marketing.']);
        }

        DB::transaction(function () use ($organization, $domain, $email, $validated, $inbox, $transactional, $marketing) {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $email,
                'password' => $validated['password'],
            ]);

            $organization->users()->attach($user->id, ['role' => 'member']);

            Mailbox::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'domain_id' => $domain->id,
                'email' => $email,
                'display_name' => $validated['name'],
                'type' => 'shared',
                'role' => $validated['role'],
                'status' => 'active',
                'inbox' => $inbox,
                'transactional' => $transactional,
                'marketing' => $marketing,
                'send_limit' => $validated['limit'] ?? 1000,
            ]);
        });

        return back()->with('success', 'User created.');
    }

    public function update(Request $request, Mailbox $mailbox): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($mailbox->organization_id === $organization->id, 404);
        abort_unless($this->access->isTeam($request->user(), $organization), 403);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'role' => ['sometimes', Rule::in(['admin', 'developer', 'staff'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'pending'])],
            'inbox' => ['sometimes', 'boolean'],
            'transactional' => ['sometimes', 'boolean'],
            'marketing' => ['sometimes', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:0'],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ]);

        DB::transaction(function () use ($mailbox, $organization, $validated) {
            $mailbox->fill([
                'display_name' => $validated['name'] ?? $mailbox->display_name,
                'role' => $validated['role'] ?? $mailbox->role,
                'status' => $validated['status'] ?? $mailbox->status,
                'inbox' => array_key_exists('inbox', $validated) ? $validated['inbox'] : $mailbox->inbox,
                'transactional' => array_key_exists('transactional', $validated) ? $validated['transactional'] : $mailbox->transactional,
                'marketing' => array_key_exists('marketing', $validated) ? $validated['marketing'] : $mailbox->marketing,
                'send_limit' => array_key_exists('limit', $validated) ? $validated['limit'] : $mailbox->send_limit,
            ])->save();

            if (! $mailbox->inbox && ! $mailbox->transactional && ! $mailbox->marketing) {
                abort(422, 'Assign at least inbox, transactional, or marketing.');
            }

            $password = $validated['password'] ?? null;

            if ($mailbox->user_id) {
                $user = User::query()->find($mailbox->user_id);
                if ($user) {
                    $user->name = $mailbox->display_name ?: $user->name;
                    if (filled($password)) {
                        $user->password = $password;
                    }
                    $user->save();
                }
            } elseif (filled($password)) {
                if (User::query()->where('email', $mailbox->email)->exists()) {
                    abort(422, 'That email is already registered as a login.');
                }

                $user = User::query()->create([
                    'name' => $mailbox->display_name ?: $mailbox->email,
                    'email' => $mailbox->email,
                    'password' => $password,
                ]);

                if (! $organization->users()->whereKey($user->id)->exists()) {
                    $organization->users()->attach($user->id, ['role' => 'member']);
                }

                $mailbox->forceFill(['user_id' => $user->id])->save();
            }
        });

        return back()->with('success', 'User updated.');
    }

    public function destroy(Request $request, Mailbox $mailbox): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($mailbox->organization_id === $organization->id, 404);
        abort_unless($this->access->isTeam($request->user(), $organization), 403);

        DB::transaction(function () use ($mailbox, $organization) {
            $userId = $mailbox->user_id;
            $mailbox->delete();

            if (! $userId) {
                return;
            }

            $organization->users()->detach($userId);

            $user = User::query()->find($userId);
            if ($user && ! $user->isPlatformAdmin() && $user->organizations()->count() === 0) {
                $user->delete();
            }
        });

        return back()->with('success', 'User deleted.');
    }

    public function approve(Request $request, Mailbox $mailbox): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($mailbox->organization_id === $organization->id, 404);
        abort_unless($this->access->isTeam($request->user(), $organization), 403);
        abort_unless($mailbox->status === 'pending', 422, 'Only pending users can be approved.');

        $mailbox->forceFill(['status' => 'active'])->save();

        return back()->with('success', $mailbox->email.' approved.');
    }

    public function reject(Request $request, Mailbox $mailbox): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($mailbox->organization_id === $organization->id, 404);
        abort_unless($this->access->isTeam($request->user(), $organization), 403);
        abort_unless($mailbox->status === 'pending', 422, 'Only pending users can be rejected.');

        return $this->destroy($request, $mailbox)->with('success', 'Registration rejected.');
    }
}
