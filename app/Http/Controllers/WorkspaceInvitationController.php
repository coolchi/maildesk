<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use App\Services\TenantResolver;
use App\Services\WorkspaceAccess;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceInvitationController extends Controller
{
    public function __construct(
        public WorkspaceAccess $workspace,
        public TenantResolver $tenants,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($this->workspace->isTeam($request->user(), $organization), 403);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::in(WorkspaceInvitation::ROLES)],
        ]);

        $email = Str::lower(trim($validated['email']));

        if ($organization->users()->whereRaw('LOWER(users.email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'That person is already a member of this workspace.',
            ]);
        }

        $pending = $organization->invitations()
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->where('email', $email)
            ->first();

        if ($pending) {
            throw ValidationException::withMessages([
                'email' => 'An invitation is already pending for that email.',
            ]);
        }

        $invitation = $organization->invitations()->create([
            'email' => $email,
            'role' => $validated['role'],
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        Notification::route('mail', $email)
            ->notify(new WorkspaceInvitationNotification($invitation));

        return back()->with('success', "Invitation sent to {$email}.");
    }

    public function destroy(Request $request, WorkspaceInvitation $invitation): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($invitation->organization_id === $organization->id, 404);
        abort_unless($this->workspace->isTeam($request->user(), $organization), 403);
        abort_unless($invitation->accepted_at === null, 422, 'That invitation was already accepted.');

        $invitation->delete();

        return back()->with('success', 'Invitation cancelled.');
    }

    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $invitation = $this->findInvitation($token);

        if ($invitation === null) {
            return redirect()->route('login')->with('status', 'That invitation link is invalid.');
        }

        if ($invitation->isAccepted()) {
            return redirect()->route('login')->with('status', 'That invitation was already accepted. Sign in to continue.');
        }

        if ($invitation->isExpired()) {
            return redirect()->route('login')->with('status', 'That invitation has expired. Ask your admin to send a new one.');
        }

        $existing = User::query()->whereRaw('LOWER(email) = ?', [$invitation->email])->first();

        if ($request->user() && $existing && $request->user()->id === $existing->id) {
            $this->acceptForUser($invitation, $existing);

            return redirect()->route($this->workspace->homeRoute($existing, $invitation->organization));
        }

        return Inertia::render('Auth/AcceptInvitation', [
            'invitation' => [
                'email' => $invitation->email,
                'role' => $invitation->role,
                'organization' => $invitation->organization->name,
                'inviter' => $invitation->inviter?->name,
                'needs_account' => $existing === null,
                'token' => $invitation->token,
            ],
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->findInvitation($token);

        if ($invitation === null || $invitation->isAccepted() || $invitation->isExpired()) {
            return redirect()->route('login')->with(
                'status',
                'That invitation is no longer valid. Ask your admin to send a new one.',
            );
        }

        $existing = User::query()->whereRaw('LOWER(email) = ?', [$invitation->email])->first();

        if ($existing) {
            if ($request->user() && $request->user()->id !== $existing->id) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            $this->acceptForUser($invitation, $existing);
            Auth::guard('web')->login($existing);
            $request->session()->regenerate();
            $request->session()->put('current_organization_id', $invitation->organization_id);

            return redirect()->route($this->workspace->homeRoute($existing, $invitation->organization));
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $this->acceptCreatingUser($invitation, $validated['name'], $validated['password']);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('current_organization_id', $invitation->organization_id);

        return redirect()->route($this->workspace->homeRoute($user, $invitation->organization));
    }

    protected function findInvitation(string $token): ?WorkspaceInvitation
    {
        return WorkspaceInvitation::query()
            ->with(['organization', 'inviter'])
            ->where('token', $token)
            ->first();
    }

    protected function acceptForUser(WorkspaceInvitation $invitation, User $user): void
    {
        DB::transaction(function () use ($invitation, $user) {
            $organization = $invitation->organization()->lockForUpdate()->first();

            if (! $organization->users()->whereKey($user->id)->exists()) {
                $organization->users()->attach($user->id, ['role' => $invitation->role]);
            }

            $this->maybeCreateMailbox($invitation, $user);
            $invitation->forceFill(['accepted_at' => now()])->save();
        });
    }

    protected function acceptCreatingUser(WorkspaceInvitation $invitation, string $name, string $password): User
    {
        return DB::transaction(function () use ($invitation, $name, $password) {
            $user = User::query()->create([
                'name' => $name,
                'email' => $invitation->email,
                'password' => $password,
                'email_verified_at' => now(),
            ]);

            $invitation->organization->users()->attach($user->id, ['role' => $invitation->role]);
            $this->maybeCreateMailbox($invitation, $user);
            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });
    }

    /**
     * Members invited with an address on a verified workspace domain get a
     * mailbox sign-in, matching Users /join creation patterns.
     */
    protected function maybeCreateMailbox(WorkspaceInvitation $invitation, User $user): void
    {
        if ($invitation->role !== 'member') {
            return;
        }

        $email = $invitation->email;
        $at = strrpos($email, '@');

        if ($at === false) {
            return;
        }

        $domainName = substr($email, $at + 1);

        /** @var Domain|null $domain */
        $domain = $invitation->organization->domains()
            ->where('status', 'verified')
            ->whereRaw('LOWER(name) = ?', [Str::lower($domainName)])
            ->first();

        if ($domain === null) {
            return;
        }

        if (Mailbox::query()->where('email', $email)->exists()) {
            return;
        }

        Mailbox::query()->create([
            'organization_id' => $invitation->organization_id,
            'user_id' => $user->id,
            'domain_id' => $domain->id,
            'email' => $email,
            'display_name' => $user->name,
            'type' => 'shared',
            'role' => 'staff',
            'status' => 'active',
            'inbox' => true,
            'transactional' => false,
            'marketing' => false,
            'send_limit' => 1000,
        ]);
    }
}
