<?php

namespace App\Support;

use App\Models\Plan;
use App\Services\Billing\BillingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PlansCatalog
{
    /**
     * Volume/price matrix for the workspace Plans modal, derived from DB plans.
     * Includes both USD (plans.price) and NGN (plans.price_kobo) so the UI can toggle.
     *
     * @return array{
     *     transactional: array<string, mixed>,
     *     marketing: array<string, mixed>,
     *     catalog: list<array<string, mixed>>,
     *     currencies: list<string>,
     *     default_currency: string
     * }
     */
    public static function forModal(): array
    {
        $plans = Schema::hasTable('plans')
            ? Plan::query()->orderBy('price')->get()
            : collect();

        $default = 'USD';
        try {
            if (app(BillingService::class)->isConfigured()) {
                $default = 'NGN';
            }
        } catch (\Throwable) {
            // BillingService may be unavailable early in boot / tests without bindings.
        }

        return [
            'transactional' => self::matrixForProduct($plans->where('product', 'transactional')->values()),
            'marketing' => self::matrixForProduct($plans->where('product', 'marketing')->values()),
            'catalog' => $plans->map(fn (Plan $plan) => $plan->toAdminArray())->values()->all(),
            'currencies' => ['USD', 'NGN'],
            'default_currency' => $default,
        ];
    }

    /**
     * @param  Collection<int, Plan>  $plans
     * @return array{
     *     volumes: list<int>,
     *     labels: list<string>,
     *     prices: array{usd: array{free: list<?int>, pro: list<?int>}, ngn: array{free: list<?int>, pro: list<?int>}},
     *     keys: array{free: ?string, pro: ?string, enterprise: ?string}
     * }
     */
    private static function matrixForProduct($plans): array
    {
        $free = $plans->first(fn (Plan $plan) => str_contains(strtolower($plan->key), 'free')
            || strtolower($plan->name) === 'free');
        $pro = $plans->first(fn (Plan $plan) => str_contains(strtolower($plan->key), 'pro')
            || strtolower($plan->name) === 'pro');
        $enterprise = $plans->first(fn (Plan $plan) => str_contains(strtolower($plan->key), 'enterprise')
            || strtolower($plan->name) === 'enterprise');

        $volumes = $plans
            ->map(fn (Plan $plan) => $plan->emails ?? $plan->contacts)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($volumes === []) {
            $volumes = [1000, 5000, 10000, 25000, 50000, 100000];
        }

        $labels = array_map(fn (int $n) => self::formatVolume($n), $volumes);

        $usdFree = [];
        $usdPro = [];
        $ngnFree = [];
        $ngnPro = [];

        foreach ($volumes as $volume) {
            $usdFree[] = self::usdAmount($free);
            $ngnFree[] = self::nairaAmount($free);

            $proCoversVolume = $pro && (
                ($pro->emails ?? $pro->contacts ?? PHP_INT_MAX) >= $volume
                || ($pro->emails === null && $pro->contacts === null)
            );

            if ($proCoversVolume) {
                $usdPro[] = self::usdAmount($pro);
                $ngnPro[] = self::nairaAmount($pro);
            } elseif ($enterprise) {
                $usdPro[] = null;
                $ngnPro[] = null;
            } else {
                $usdPro[] = self::usdAmount($pro);
                $ngnPro[] = self::nairaAmount($pro);
            }
        }

        return [
            'volumes' => $volumes,
            'labels' => $labels,
            'prices' => [
                'usd' => [
                    'free' => $usdFree,
                    'pro' => $usdPro,
                ],
                'ngn' => [
                    'free' => $ngnFree,
                    'pro' => $ngnPro,
                ],
            ],
            'keys' => [
                'free' => $free?->key,
                'pro' => $pro?->key,
                'enterprise' => $enterprise?->key,
            ],
        ];
    }

    private static function usdAmount(?Plan $plan): ?int
    {
        if ($plan === null) {
            return null;
        }

        return (int) $plan->price;
    }

    /**
     * Whole naira for display. Free plans are ₦0; paid plans use price_kobo
     * (or USD × naira_per_price_unit when kobo is unset).
     */
    private static function nairaAmount(?Plan $plan): ?int
    {
        if ($plan === null) {
            return null;
        }

        if ((int) $plan->price <= 0) {
            return 0;
        }

        if ($plan->price_kobo !== null) {
            return (int) round(((int) $plan->price_kobo) / 100);
        }

        $rate = (float) config('services.monipay.naira_per_price_unit', 1) ?: 1.0;

        return (int) round(((int) $plan->price) * $rate);
    }

    private static function formatVolume(int $n): string
    {
        if ($n >= 1000000) {
            return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.').'M+';
        }
        if ($n >= 1000) {
            return number_format($n / 1000).'k';
        }

        return number_format($n);
    }
}
