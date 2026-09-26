<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Support\Str;

/**
 * Starts and expires workspace free trials (no free tier).
 */
class TrialService
{
    public function __construct(public PlatformSettings $settings) {}

    public function trialDays(): int
    {
        return max(1, (int) $this->settings->get('trial_days', 14));
    }

    /**
     * Attach a trial subscription and label the org. Call inside a DB transaction.
     */
    public function start(Organization $organization): Subscription
    {
        $days = $this->trialDays();
        $ends = now()->addDays($days);

        $subscription = Subscription::query()->create([
            'key' => 'sub_'.Str::lower((string) Str::ulid()),
            'organization_id' => $organization->id,
            'plan_id' => null,
            'plan_name' => 'Trial',
            'product' => $organization->product ?: 'transactional',
            'status' => 'trial',
            'price' => 0,
            'seats' => max(1, (int) $organization->seats),
            'renews_at' => $ends->format('M j, Y'),
            'current_period_ends_at' => $ends,
        ]);

        $organization->forceFill([
            'status' => 'trial',
            'plan' => 'Trial',
            'mrr' => 0,
        ])->save();

        return $subscription;
    }

    /**
     * Mark expired trial workspaces as past_due (full lockout except billing).
     */
    public function expireDue(): int
    {
        $count = 0;

        Organization::query()
            ->where('status', 'trial')
            ->whereHas('subscriptions', function ($query) {
                $query->where('status', 'trial')
                    ->whereNotNull('current_period_ends_at')
                    ->where('current_period_ends_at', '<=', now());
            })
            ->orderBy('id')
            ->each(function (Organization $organization) use (&$count) {
                $organization->forceFill(['status' => 'past_due'])->save();
                $organization->subscriptions()
                    ->where('status', 'trial')
                    ->where('current_period_ends_at', '<=', now())
                    ->update(['status' => 'past_due']);
                $count++;
            });

        return $count;
    }
}
