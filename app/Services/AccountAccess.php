<?php

namespace App\Services;

use App\Exceptions\AccountSuspendedException;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for "may this account be used / send mail?".
 *
 * An organization is blocked when it is suspended by a platform admin or
 * soft-deleted (closed). Platform admins are never locked out.
 */
class AccountAccess
{
    public const SUSPENDED_MESSAGE = 'This account has been suspended. Contact support to restore access.';

    public const CLOSED_MESSAGE = 'This account has been closed.';

    public function isSuspended(?Organization $organization): bool
    {
        return $organization !== null && $organization->status === 'suspended';
    }

    public function isClosed(?Organization $organization): bool
    {
        return $organization === null
            || (method_exists($organization, 'trashed') && $organization->trashed());
    }

    public function organizationBlocked(?Organization $organization): bool
    {
        return $this->isClosed($organization) || $this->isSuspended($organization);
    }

    public function reasonFor(?Organization $organization): string
    {
        return $this->isClosed($organization) ? self::CLOSED_MESSAGE : self::SUSPENDED_MESSAGE;
    }

    /**
     * @throws AccountSuspendedException
     */
    public function assertCanSend(?Organization $organization): void
    {
        if ($this->organizationBlocked($organization)) {
            throw new AccountSuspendedException($this->reasonFor($organization));
        }
    }

    /**
     * Organizations the user can still work in (not suspended, not closed).
     *
     * @return Collection<int, Organization>
     */
    public function usableOrganizations(User $user): Collection
    {
        return $user->organizations()
            ->where(fn ($q) => $q->whereNull('organizations.status')->orWhere('organizations.status', '!=', 'suspended'))
            ->orderBy('name')
            ->get();
    }

    public function hasMemberships(User $user): bool
    {
        return DB::table('organization_user')->where('user_id', $user->id)->exists();
    }

    /**
     * A non-admin user is locked out when they belong to at least one
     * organization and every one of them is suspended or closed. Users with
     * no memberships yet (fresh signups) are not affected.
     */
    public function isLockedOut(User $user): bool
    {
        if ($user->isPlatformAdmin() || ! $this->hasMemberships($user)) {
            return false;
        }

        return $this->usableOrganizations($user)->isEmpty();
    }

    public function lockoutMessage(User $user): string
    {
        $anySuspended = $user->organizations()->where('organizations.status', 'suspended')->exists();

        return $anySuspended ? self::SUSPENDED_MESSAGE : self::CLOSED_MESSAGE;
    }
}
