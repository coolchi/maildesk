<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Catalog of transactional and marketing plans, with USD and naira prices.
 * Paid naira amounts are stored as kobo and sit above the Monipay minimum.
 * Safe to re-run: rows are matched by plan key.
 */
class PlansSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['tx_free' => 'tx_starter', 'mkt_free' => 'mkt_starter'] as $from => $to) {
            if (Plan::query()->where('key', $to)->exists()) {
                Plan::query()->where('key', $from)->delete();
            } else {
                Plan::query()->where('key', $from)->update(['key' => $to]);
            }
        }

        foreach ($this->plans() as $plan) {
            Plan::query()->updateOrCreate(
                ['key' => $plan['key']],
                $plan,
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function plans(): array
    {
        return [
            [
                'key' => 'tx_starter',
                'product' => 'transactional',
                'name' => 'Starter',
                'price' => 10,
                'price_kobo' => 1_000_000, // ₦10,000 ($1 = ₦1,000, same rate as Pro)
                'interval' => 'month',
                'emails' => 10000,
                'contacts' => null,
                'seats' => 1,
                'featured' => false,
                'features' => [
                    ['id' => 'f1', 'label' => '10,000 emails / month', 'included' => true],
                    ['id' => 'f2', 'label' => '1 domain', 'included' => true],
                    ['id' => 'f3', 'label' => 'Community support', 'included' => true],
                    ['id' => 'f4', 'label' => 'Dedicated IPs', 'included' => false],
                    ['id' => 'f5', 'label' => 'SSO', 'included' => false],
                ],
            ],
            [
                'key' => 'tx_pro',
                'product' => 'transactional',
                'name' => 'Pro',
                'price' => 20,
                'price_kobo' => 2_000_000, // ₦20,000
                'interval' => 'month',
                'emails' => 50000,
                'contacts' => null,
                'seats' => 10,
                'featured' => true,
                'features' => [
                    ['id' => 'f1', 'label' => '50,000 emails / month', 'included' => true],
                    ['id' => 'f2', 'label' => 'Unlimited domains', 'included' => true],
                    ['id' => 'f3', 'label' => 'Slack + ticket support', 'included' => true],
                    ['id' => 'f4', 'label' => 'Dedicated IPs (add-on)', 'included' => false],
                    ['id' => 'f5', 'label' => 'SSO', 'included' => false],
                ],
            ],
            [
                'key' => 'tx_enterprise',
                'product' => 'transactional',
                'name' => 'Enterprise',
                'price' => 999,
                'price_kobo' => 25_000_000, // ₦250,000
                'interval' => 'month',
                'emails' => null,
                'contacts' => null,
                'seats' => null,
                'featured' => false,
                'features' => [
                    ['id' => 'f1', 'label' => 'Custom volume', 'included' => true],
                    ['id' => 'f2', 'label' => 'Unlimited domains', 'included' => true],
                    ['id' => 'f3', 'label' => 'Priority support', 'included' => true],
                    ['id' => 'f4', 'label' => 'Dedicated IPs', 'included' => true],
                    ['id' => 'f5', 'label' => 'SSO', 'included' => true],
                ],
            ],
            [
                'key' => 'mkt_starter',
                'product' => 'marketing',
                'name' => 'Starter',
                'price' => 10,
                'price_kobo' => 1_000_000, // ₦10,000
                'interval' => 'month',
                'emails' => null,
                'contacts' => 10000,
                'seats' => 1,
                'featured' => false,
                'features' => [
                    ['id' => 'f1', 'label' => '10,000 contacts', 'included' => true],
                    ['id' => 'f2', 'label' => '3 segments', 'included' => true],
                    ['id' => 'f3', 'label' => 'Ticket support', 'included' => true],
                    ['id' => 'f4', 'label' => 'Marketing analytics', 'included' => false],
                    ['id' => 'f5', 'label' => 'SSO', 'included' => false],
                ],
            ],
            [
                'key' => 'mkt_pro',
                'product' => 'marketing',
                'name' => 'Pro',
                'price' => 20,
                'price_kobo' => 2_000_000, // ₦20,000
                'interval' => 'month',
                'emails' => null,
                'contacts' => 10000,
                'seats' => 10,
                'featured' => true,
                'features' => [
                    ['id' => 'f1', 'label' => '10,000 contacts', 'included' => true],
                    ['id' => 'f2', 'label' => 'Unlimited segments', 'included' => true],
                    ['id' => 'f3', 'label' => 'Slack + ticket support', 'included' => true],
                    ['id' => 'f4', 'label' => 'Marketing analytics', 'included' => true],
                    ['id' => 'f5', 'label' => 'SSO', 'included' => false],
                ],
            ],
            [
                'key' => 'mkt_enterprise',
                'product' => 'marketing',
                'name' => 'Enterprise',
                'price' => 499,
                'price_kobo' => 12_500_000, // ₦125,000
                'interval' => 'month',
                'emails' => null,
                'contacts' => null,
                'seats' => null,
                'featured' => false,
                'features' => [
                    ['id' => 'f1', 'label' => 'Custom contacts', 'included' => true],
                    ['id' => 'f2', 'label' => 'Unlimited segments', 'included' => true],
                    ['id' => 'f3', 'label' => 'Priority support', 'included' => true],
                    ['id' => 'f4', 'label' => 'Marketing analytics', 'included' => true],
                    ['id' => 'f5', 'label' => 'SSO', 'included' => true],
                ],
            ],
        ];
    }
}
