<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Collection;

/**
 * The one place MRR is computed.
 *
 * MRR = sum of active, paid subscriptions (price > 0) on non-deleted
 * accounts, each normalised to a monthly amount: yearly plans are divided
 * by 12. Amounts are in the plan's USD price units (plans.price).
 */
class RevenueService
{
    public function isYearly(?string $interval): bool
    {
        return in_array(strtolower((string) $interval), ['year', 'yearly', 'annual', 'annually'], true);
    }

    public function monthlyAmount(int|float $price, ?string $interval): float
    {
        return $this->isYearly($interval) ? $price / 12 : (float) $price;
    }

    /**
     * @return Collection<int, Subscription>
     */
    public function activePaidSubscriptions(): Collection
    {
        return Subscription::query()
            ->with(['plan', 'organization'])
            ->whereHas('organization')
            ->where('status', 'active')
            ->where('price', '>', 0)
            ->get();
    }

    public function subscriptionMonthly(Subscription $subscription): float
    {
        return $this->monthlyAmount((int) $subscription->price, $subscription->plan?->interval);
    }

    public function mrr(): float
    {
        return round($this->activePaidSubscriptions()->sum(fn (Subscription $s) => $this->subscriptionMonthly($s)), 2);
    }

    /**
     * Per-plan breakdown for the Revenue page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function breakdown(): array
    {
        return $this->activePaidSubscriptions()
            ->groupBy(fn (Subscription $s) => $s->plan_id ?? 'none:'.$s->plan_name)
            ->map(function (Collection $subs) {
                /** @var Subscription $first */
                $first = $subs->first();
                $mrr = $subs->sum(fn (Subscription $s) => $this->subscriptionMonthly($s));

                return [
                    'plan' => $first->plan?->name ?? $first->plan_name,
                    'planKey' => $first->plan?->key,
                    'product' => $first->plan?->product ?? $first->product,
                    'interval' => $first->plan?->interval ?? 'month',
                    'price' => (int) ($first->plan?->price ?? $first->price),
                    'subscriptions' => $subs->count(),
                    'mrr' => round($mrr, 2),
                ];
            })
            ->sortByDesc('mrr')
            ->values()
            ->all();
    }

    /**
     * organizations.mrr for one account from its latest subscription.
     */
    public function organizationMrr(Organization $organization): int
    {
        $subscription = $organization->subscription()->with('plan')->first();

        if (! $subscription || $subscription->status !== 'active') {
            return 0;
        }

        return (int) round($this->subscriptionMonthly($subscription));
    }

    public function syncOrganization(Organization $organization): void
    {
        $organization->forceFill(['mrr' => $this->organizationMrr($organization)])->save();
    }

    /**
     * Recompute organizations.mrr for every account on this plan.
     */
    public function syncPlan(Plan $plan): int
    {
        $count = 0;

        Organization::query()
            ->whereHas('subscriptions', fn ($q) => $q->where('plan_id', $plan->id))
            ->each(function (Organization $organization) use (&$count): void {
                $this->syncOrganization($organization);
                $count++;
            });

        return $count;
    }
}
