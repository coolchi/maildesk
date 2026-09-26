<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Membership management for one account (organization_user pivot, roles
 * owner / admin / member). JSON endpoints used by AccountUsersPanel.vue.
 *
 * New users are created with a random password that is never shown or
 * emailed; they get access through "Forgot password" on the login page.
 */
class AccountUserController extends Controller
{
    public const ROLES = ['owner', 'admin', 'member'];

    public function index(Organization $organization): JsonResponse
    {
        return response()->json(['users' => $this->members($organization)]);
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'role' => ['required', Rule::in(self::ROLES)],
        ]);

        $email = Str::lower(trim($validated['email']));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        $created = false;

        if ($user && $organization->users()->whereKey($user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'That user is already a member of this account.']);
        }

        if (! $user) {
            if (blank($validated['name'] ?? null)) {
                throw ValidationException::withMessages(['name' => 'Enter a name to create a new user.']);
            }

            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $email,
                // Random, never displayed or sent. The user sets their own
                // password via "Forgot password".
                'password' => Str::password(32),
            ]);
            $created = true;
        }

        $organization->users()->attach($user->id, ['role' => $validated['role']]);

        return response()->json([
            'message' => $created
                ? "Created {$user->email} and added as {$validated['role']}. They can set a password with \"Forgot password\" on the login page."
                : "Added {$user->email} as {$validated['role']}.",
            'created' => $created,
            'users' => $this->members($organization),
        ], 201);
    }

    public function update(Request $request, Organization $organization, User $user): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(self::ROLES)],
        ]);

        $current = $this->roleOf($organization, $user);

        if ($user->isPlatformAdmin()) {
            throw ValidationException::withMessages(['user' => 'Platform admins cannot be changed from this screen.']);
        }

        if ($current === 'owner' && $validated['role'] !== 'owner' && $this->ownerCount($organization) <= 1) {
            throw ValidationException::withMessages(['user' => 'An account must keep at least one owner.']);
        }

        $organization->users()->updateExistingPivot($user->id, ['role' => $validated['role']]);

        return response()->json([
            'message' => "{$user->email} is now {$validated['role']}.",
            'users' => $this->members($organization),
        ]);
    }

    public function destroy(Organization $organization, User $user): JsonResponse
    {
        $current = $this->roleOf($organization, $user);

        if ($user->isPlatformAdmin()) {
            throw ValidationException::withMessages(['user' => 'Platform admins cannot be removed from this screen.']);
        }

        if ($current === 'owner' && $this->ownerCount($organization) <= 1) {
            throw ValidationException::withMessages(['user' => 'You cannot remove the last owner of an account.']);
        }

        $organization->users()->detach($user->id);

        return response()->json([
            'message' => "Removed {$user->email} from {$organization->name}.",
            'users' => $this->members($organization),
        ]);
    }

    protected function roleOf(Organization $organization, User $user): string
    {
        $member = $organization->users()->whereKey($user->id)->first();

        abort_if($member === null, 404, 'That user is not a member of this account.');

        return (string) $member->pivot->role;
    }

    protected function ownerCount(Organization $organization): int
    {
        return $organization->users()->wherePivot('role', 'owner')->count();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function members(Organization $organization): array
    {
        $owners = $this->ownerCount($organization);

        return $organization->users()
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->pivot->role,
                'is_platform_admin' => $user->isPlatformAdmin(),
                'is_last_owner' => $user->pivot->role === 'owner' && $owners <= 1,
                'joined' => $user->pivot->created_at?->format('M j, Y'),
            ])
            ->values()
            ->all();
    }
}
