<?php

namespace App\Services;

use App\Exceptions\AccountSuspendedException;
use App\Exceptions\WorkspaceAccessException;
use App\Mail\MailManager;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for "may this account be used / send mail?".
 *
 * An organization is blocked when it is suspended by a platform admin or
 * soft-deleted (closed). Expired trials become past_due and are limited to
 * Settings/Billing until payment. Platform admins are never locked out.
 */
class AccountAccess
{
    public const SUSPENDED_MESSAGE = 'This account has been suspended. Contact support to restore access.';

    public const CLOSED_MESSAGE = 'This account has been closed.';

    public const TRIAL_EXPIRED_MESSAGE = 'Your free trial has ended. Choose a plan to continue using this workspace.';

    public const QUOTA_MESSAGE = 'This workspace has reached its plan email limit for the current period. Upgrade or wait until the period renews.';

    public function isSuspended(?Organization $organization): bool
    {
        return $organization !== null && $organization->status === 'suspended';
    }

    public function isClosed(?Organization $organization): bool
    {
        return $organization === null
            || (method_exists($organization, 'trashed') && $organization->trashed());
    }

    public function requiresPayment(?Organization $organization): bool
    {
        if ($organization === null) {
            return false;
        }

        if ($organization->status === 'past_due') {
            return true;
        }

        if ($organization->status === 'trial') {
            $ends = $this->trialEndsAt($organization);

            return $ends !== null && $ends->isPast();
        }

        return false;
    }

    public function trialEndsAt(?Organization $organization): ?Carbon
    {
        if ($organization === null) {
            return null;
        }

        $subscription = $organization->relationLoaded('subscriptions')
            ? $organization->subscriptions->firstWhere('status', 'trial')
                ?? $organization->subscriptions->sortByDesc('id')->first()
            : $organization->subscriptions()->latest('id')->first();

        return $subscription?->current_period_ends_at;
    }

    public function paymentRequiredMessage(?Organization $organization): string
    {
        return self::TRIAL_EXPIRED_MESSAGE;
    }

    /**
     * Routes still reachable while the workspace must pay.
     */
    public function routeAllowedWhileLocked(Request $request): bool
    {
        return $request->routeIs(
            'settings',
            'settings.*',
            'billing.*',
            'profile.*',
            'logout',
            'verification.*',
            'password.*',
        );
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
     * @throws WorkspaceAccessException
     */
    public function assertCanSend(?Organization $organization): void
    {
        if ($this->organizationBlocked($organization)) {
            throw new AccountSuspendedException($this->reasonFor($organization));
        }

        if ($this->requiresPayment($organization)) {
            throw new WorkspaceAccessException($this->paymentRequiredMessage($organization));
        }

        if ($organization && ! app(MailManager::class)->canSendFor($organization)) {
            throw new WorkspaceAccessException(
                'Cannot send — this workspace has no active mail provider or SMTP settings.',
            );
        }

        $this->assertWithinEmailQuota($organization);
    }

    /**
     * @throws WorkspaceAccessException
     */
    public function assertWithinEmailQuota(?Organization $organization): void
    {
        if ($organization === null || $organization->status === 'trial') {
            // Unlimited during active trial.
            return;
        }

        $subscription = $this->billingSubscription($organization);
        $plan = $subscription?->plan ?? Plan::query()->where('key', $subscription?->plan_id)->first();

        if (! $subscription || ! $plan || ! $plan->emails) {
            return;
        }

        $periodStart = $subscription->current_period_ends_at
            ? $subscription->current_period_ends_at->copy()->subMonthNoOverflow()
            : now()->subDays(30);

        // Prefer period start from renews window: last fulfilment start ≈ ends - interval.
        if ($subscription->current_period_ends_at && str_contains(strtolower((string) $plan->interval), 'year')) {
            $periodStart = $subscription->current_period_ends_at->copy()->subYearNoOverflow();
        }

        $used = $organization->messages()
            ->where('direction', 'outbound')
            ->where('created_at', '>=', $periodStart)
            ->count();

        if ($used >= (int) $plan->emails) {
            throw new WorkspaceAccessException(self::QUOTA_MESSAGE);
        }
    }

    public function billingSubscription(Organization $organization): ?Subscription
    {
        if ($organization->relationLoaded('subscription') && $organization->subscription) {
            return $organization->subscription->loadMissing('plan');
        }

        return $organization->subscriptions()->with('plan')->latest('id')->first();
    }

    /**
     * @return array{lockout: bool, reason: string|null, trial_ends_at: string|null, status: string|null}
     */
    public function sharedAccessState(?Organization $organization): array
    {
        $trialEnds = $this->trialEndsAt($organization);

        return [
            'lockout' => $this->requiresPayment($organization),
            'reason' => $this->requiresPayment($organization)
                ? $this->paymentRequiredMessage($organization)
                : null,
            'trial_ends_at' => $trialEnds?->toIso8601String(),
            'status' => $organization?->status,
        ];
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
