<?php

namespace App\Support;

use App\Models\Plan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PlansCatalog
{
    /**
     * Volume/price matrix for the workspace Plans modal, derived from DB plans.
     *
     * @return array{
     *     transactional: array<string, mixed>,
     *     marketing: array<string, mixed>,
     *     catalog: list<array<string, mixed>>
     * }
     */
    public static function forModal(): array
    {
        $plans = Schema::hasTable('plans')
            ? Plan::query()->orderBy('price')->get()
            : collect();

        return [
            'transactional' => self::matrixForProduct($plans->where('product', 'transactional')->values()),
            'marketing' => self::matrixForProduct($plans->where('product', 'marketing')->values()),
            'catalog' => $plans->map(fn (Plan $plan) => $plan->toAdminArray())->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, Plan>  $plans
     * @return array{volumes: list<int>, labels: list<string>, prices: array{free: list<?int>, pro: list<?int>}}
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

        $freePrices = [];
        $proPrices = [];
        foreach ($volumes as $volume) {
            $freePrices[] = $free ? (int) $free->price : 0;
            if ($pro && (($pro->emails ?? $pro->contacts ?? PHP_INT_MAX) >= $volume || $pro->emails === null && $pro->contacts === null)) {
                $proPrices[] = (int) $pro->price;
            } elseif ($enterprise) {
                $proPrices[] = null;
            } else {
                $proPrices[] = $pro ? (int) $pro->price : null;
            }
        }

        return [
            'volumes' => $volumes,
            'labels' => $labels,
            'prices' => [
                'free' => $freePrices,
                'pro' => $proPrices,
            ],
        ];
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
