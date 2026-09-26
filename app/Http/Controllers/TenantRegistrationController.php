<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Models\Organization;
use App\Models\User;
use App\Services\TenantResolver;
use App\Services\WorkspaceAccess;
use App\Support\UserRegistrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class TenantRegistrationController extends Controller
{
    public function __construct(
        public TenantResolver $tenants,
        public WorkspaceAccess $workspace,
    ) {}

    public function create(Request $request): Response|RedirectResponse
    {
        $organization = $this->organizationFromRequest($request);

        if (! $organization || ! UserRegistrationSettings::enabled($organization)) {
            return redirect()->route('login')->with(
                'status',
                'User registration is not open for this workspace.',
            );
        }

        if ($request->user()) {
            if ($this->userBelongsToOrganization($request->user(), $organization)) {
                return redirect()->route($this->workspace->homeRoute($request->user(), $organization));
            }

            // Different account is signed in — clear it so they can register here.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $domains = $organization->domains()
            ->where('status', 'verified')
            ->orderBy('name')
            ->pluck('name')
            ->values()
            ->all();

        if ($domains === []) {
            abort(404, 'This workspace has no verified domains for registration.');
        }

        $settings = UserRegistrationSettings::for($organization);

        return Inertia::render('Auth/TenantJoin', [
            'organization' => [
                'name' => $organization->name,
            ],
            'domains' => $domains,
            'requiresApproval' => $settings['approval'] === UserRegistrationSettings::APPROVAL_MANUAL,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->organizationFromRequest($request);

        if (! $organization || ! UserRegistrationSettings::enabled($organization)) {
            return redirect()->route('login')->with(
                'status',
                'User registration is not open for this workspace.',
            );
        }

        if ($request->user()) {
            if ($this->userBelongsToOrganization($request->user(), $organization)) {
                return redirect()->route($this->workspace->homeRoute($request->user(), $organization));
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $settings = UserRegistrationSettings::for($organization);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'local' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9._+-]+$/i'],
            'domain' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $domain = $organization->domains()
            ->where('status', 'verified')
            ->where('name', strtolower($validated['domain']))
            ->first();

        if (! $domain) {
            return back()->withErrors(['domain' => 'Choose a verified domain for this workspace.']);
        }

        $email = strtolower($validated['local']).'@'.$domain->name;

        if (Mailbox::query()->where('email', $email)->exists()) {
            return back()->withErrors(['local' => 'That address is already taken.']);
        }

        if (User::query()->where('email', $email)->exists()) {
            return back()->withErrors(['local' => 'That email is already registered.']);
        }

        $manual = $settings['approval'] === UserRegistrationSettings::APPROVAL_MANUAL;
        $status = $manual ? 'pending' : 'active';

        $user = DB::transaction(function () use ($organization, $domain, $email, $validated, $settings, $status) {
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
                'role' => $settings['default_role'],
                'status' => $status,
                'inbox' => $settings['default_inbox'],
                'transactional' => $settings['default_transactional'],
                'marketing' => $settings['default_marketing'],
                'send_limit' => 1000,
            ]);

            return $user;
        });

        if ($manual) {
            return redirect()->route('login')->with(
                'status',
                'Your account was submitted and is awaiting approval from your workspace admin.',
            );
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('current_organization_id', $organization->id);

        return redirect()->route($this->workspace->homeRoute($user, $organization));
    }

    protected function organizationFromRequest(Request $request): ?Organization
    {
        $fromAttribute = $request->attributes->get('organization');
        if ($fromAttribute instanceof Organization) {
            return $fromAttribute;
        }

        return $this->tenants->resolveFromHost($this->tenants->hostFromRequest($request));
    }

    protected function userBelongsToOrganization(User $user, Organization $organization): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        return $user->organizations()->whereKey($organization->id)->exists();
    }
}
