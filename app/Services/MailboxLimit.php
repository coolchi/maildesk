<?php

namespace App\Services;

use App\Models\Organization;

/**
 * Plan-based cap on how many email addresses (mailboxes) a workspace may create.
 *
 * - Paid plan: plans.mailbox_limit (null = unlimited).
 * - Trial without a plan: the platform setting trial_mailbox_limit (blank = unlimited).
 * - No subscription at all: unlimited.
 *
 * Existing mailboxes are never removed; a workspace at or over its limit just
 * cannot add more. Used by the Users page and POST /api/v1/mailboxes.
 */
class MailboxLimit
{
    public function __construct(public PlatformSettings $settings) {}

    public function limitFor(Organization $organization): ?int
    {
        $subscription = $organization->subscription()->with('plan')->first();

        if ($subscription?->plan) {
            $limit = $subscription->plan->mailbox_limit;

            return $limit === null ? null : (int) $limit;
        }

        if ($subscription?->status === 'trial' || $organization->status === 'trial') {
            return $this->settings->trialMailboxLimit();
        }

        return null;
    }

    public function used(Organization $organization): int
    {
        return $organization->mailboxes()->count();
    }

    public function canCreate(Organization $organization): bool
    {
        $limit = $this->limitFor($organization);

        return $limit === null || $this->used($organization) < $limit;
    }

    /**
     * @return array{limit: ?int, used: int, remaining: ?int}
     */
    public function usage(Organization $organization): array
    {
        $limit = $this->limitFor($organization);
        $used = $this->used($organization);

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
        ];
    }

    public function message(Organization $organization): string
    {
        $limit = (int) $this->limitFor($organization);
        $used = $this->used($organization);
        $noun = $limit === 1 ? 'email address' : 'email addresses';

        return "Your plan allows {$limit} {$noun} and this workspace has {$used}. Upgrade your plan or delete an address to add another.";
    }
}
