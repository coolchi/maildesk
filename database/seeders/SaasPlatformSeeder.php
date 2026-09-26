<?php

namespace Database\Seeders;

use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\OrganizationHost;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SaasPlatformSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@maildesk.ng'],
            [
                'name' => 'MailDesk Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'is_platform_admin' => true,
            ],
        );

        User::query()->updateOrCreate(
            ['email' => 'member@test.com'],
            [
                'name' => 'Regular Member',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'is_platform_admin' => false,
            ],
        );

        $providers = [
            [
                'key' => 'resend',
                'name' => 'Resend Production',
                'driver' => 'resend',
                'type' => 'api',
                'status' => 'active',
                'is_default' => true,
                'api_base' => 'https://api.resend.com',
                'regions' => ['us-east-1', 'eu-west-1'],
                'features' => ['Transactional', 'Inbound', 'Webhooks', 'Domains'],
                'description' => 'Primary API provider for transactional and marketing mail.',
                'config' => [
                    ['key' => 'API_KEY', 'value' => 're_live_9f2a••••••••c81e', 'secret' => true],
                    ['key' => 'WEBHOOK_SECRET', 'value' => 'whsec_4b••••••••a2', 'secret' => true],
                ],
            ],
            [
                'key' => 'postmark',
                'name' => 'Postmark Main',
                'driver' => 'postmark',
                'type' => 'api',
                'status' => 'active',
                'is_default' => false,
                'api_base' => 'https://api.postmarkapp.com',
                'regions' => ['us-east-1'],
                'features' => ['Transactional', 'Templates', 'Webhooks'],
                'description' => 'High-deliverability transactional specialist.',
                'config' => [
                    ['key' => 'SERVER_TOKEN', 'value' => 'pm-server-••••••••91', 'secret' => true],
                    ['key' => 'ACCOUNT_TOKEN', 'value' => '', 'secret' => true],
                ],
            ],
            [
                'key' => 'sendgrid',
                'name' => 'SendGrid Volume',
                'driver' => 'sendgrid',
                'type' => 'api',
                'status' => 'active',
                'is_default' => false,
                'api_base' => 'https://api.sendgrid.com',
                'regions' => ['us-east-1', 'eu-west-1'],
                'features' => ['Transactional', 'Marketing', 'Webhooks'],
                'description' => 'Twilio SendGrid for high-volume sends.',
                'config' => [
                    ['key' => 'API_KEY', 'value' => 'SG.••••••••••••', 'secret' => true],
                ],
            ],
            [
                'key' => 'ses',
                'name' => 'Amazon SES EU',
                'driver' => 'ses',
                'type' => 'api',
                'status' => 'active',
                'is_default' => false,
                'api_base' => 'https://email.{region}.amazonaws.com',
                'regions' => ['us-east-1', 'eu-west-1', 'ap-southeast-1'],
                'features' => ['Transactional', 'Dedicated IPs'],
                'description' => 'AWS SES for cost-efficient scale.',
                'config' => [
                    ['key' => 'ACCESS_KEY_ID', 'value' => 'AKIA••••••••Z3', 'secret' => false],
                    ['key' => 'SECRET_ACCESS_KEY', 'value' => '••••••••••••••••', 'secret' => true],
                    ['key' => 'REGION', 'value' => 'eu-west-1', 'secret' => false],
                ],
            ],
            [
                'key' => 'smtp',
                'name' => 'Relay SMTP',
                'driver' => 'smtp',
                'type' => 'smtp',
                'status' => 'active',
                'is_default' => false,
                'api_base' => null,
                'regions' => ['global'],
                'features' => ['SMTP relay', 'Custom hosts'],
                'description' => 'Bring-your-own SMTP for legacy stacks.',
                'config' => [
                    ['key' => 'HOST', 'value' => 'smtp.maildesk.test', 'secret' => false],
                    ['key' => 'PORT', 'value' => '587', 'secret' => false],
                    ['key' => 'USERNAME', 'value' => 'relay@maildesk.test', 'secret' => false],
                    ['key' => 'PASSWORD', 'value' => '••••••••', 'secret' => true],
                    ['key' => 'ENCRYPTION', 'value' => 'tls', 'secret' => false],
                ],
            ],
            [
                'key' => 'mailgun',
                'name' => 'Mailgun (disabled)',
                'driver' => 'mailgun',
                'type' => 'api',
                'status' => 'disabled',
                'is_default' => false,
                'api_base' => 'https://api.mailgun.net',
                'regions' => ['us', 'eu'],
                'features' => ['Transactional', 'Inbound'],
                'description' => 'Optional provider — disabled on this platform.',
                'config' => [
                    ['key' => 'API_KEY', 'value' => '', 'secret' => true],
                    ['key' => 'DOMAIN', 'value' => '', 'secret' => false],
                    ['key' => 'REGION', 'value' => 'us', 'secret' => false],
                ],
            ],
            [
                'key' => 'resend_staging',
                'name' => 'Resend Staging',
                'driver' => 'resend',
                'type' => 'api',
                'status' => 'active',
                'is_default' => false,
                'api_base' => 'https://api.resend.com',
                'regions' => ['us-east-1'],
                'features' => ['Transactional', 'Webhooks'],
                'description' => 'Non-production Resend project for trials and demos.',
                'config' => [
                    ['key' => 'API_KEY', 'value' => 're_test_••••••••ab12', 'secret' => true],
                    ['key' => 'WEBHOOK_SECRET', 'value' => 'whsec_test_••••', 'secret' => true],
                ],
            ],
        ];

        $providerModels = [];
        foreach ($providers as $data) {
            $providerModels[$data['key']] = MailProvider::query()->updateOrCreate(
                ['key' => $data['key']],
                $data,
            );
        }

        $this->call(PlansSeeder::class);

        $planModels = Plan::query()->get()->keyBy('key');

        $accounts = [
            [
                'name' => 'Acme Mail',
                'slug' => 'acme-mail',
                'provider' => 'resend',
                'status' => 'active',
                'plan' => 'Pro',
                'product' => 'transactional',
                'subdomain' => 'acme',
                'mrr' => 20,
                'seats' => 4,
                'emails_30d' => 12840,
                'owner_name' => 'Ade Tola',
                'owner_email' => 'admin@maildesk.ng',
                'provisioned_at' => '2026-01-12',
                'subscription' => ['key' => 'sub_1', 'plan' => 'tx_pro', 'price' => 20, 'renews' => 'Oct 4, 2026'],
            ],
            [
                'name' => 'Northwind Labs',
                'slug' => 'northwind-labs',
                'provider' => 'resend',
                'status' => 'active',
                'plan' => 'Free',
                'product' => 'marketing',
                'subdomain' => 'northwind',
                'mrr' => 0,
                'seats' => 2,
                'emails_30d' => 420,
                'owner_name' => 'Jordan Lee',
                'owner_email' => 'jordan@northwind.io',
                'provisioned_at' => '2026-02-03',
                'subscription' => ['key' => 'sub_5', 'plan' => 'mkt_starter', 'price' => 10, 'renews' => '—'],
            ],
            [
                'name' => 'Harbor FM',
                'slug' => 'harbor-fm',
                'provider' => 'smtp',
                'status' => 'active',
                'plan' => 'Pro',
                'product' => 'transactional',
                'subdomain' => 'harbor',
                'custom_domain' => 'mail.harbor.fm',
                'mrr' => 80,
                'seats' => 12,
                'emails_30d' => 94200,
                'region' => 'eu-west-1',
                'owner_name' => 'Sam Okonkwo',
                'owner_email' => 'sam@harbor.fm',
                'provisioned_at' => '2025-11-18',
                'subscription' => ['key' => 'sub_2', 'plan' => 'tx_pro', 'price' => 80, 'renews' => 'Oct 1, 2026'],
                'extra_hosts' => [
                    ['host' => 'mail.harbor.fm', 'custom' => true, 'status' => 'active', 'ssl' => true],
                ],
            ],
            [
                'name' => 'Brightpath',
                'slug' => 'brightpath',
                'provider' => 'postmark',
                'status' => 'past_due',
                'plan' => 'Pro',
                'product' => 'marketing',
                'subdomain' => 'brightpath',
                'mrr' => 49,
                'seats' => 6,
                'emails_30d' => 2100,
                'owner_name' => 'Mia Chen',
                'owner_email' => 'mia@brightpath.app',
                'provisioned_at' => '2026-03-02',
                'subscription' => ['key' => 'sub_3', 'plan' => 'mkt_pro', 'price' => 49, 'renews' => 'Sep 12, 2026', 'status' => 'past_due'],
            ],
            [
                'name' => 'Orbit Retail',
                'slug' => 'orbit-retail',
                'provider' => 'ses',
                'status' => 'active',
                'plan' => 'Enterprise',
                'product' => 'transactional',
                'subdomain' => 'orbit',
                'custom_domain' => 'send.orbit.store',
                'mrr' => 1200,
                'seats' => 40,
                'emails_30d' => 1240000,
                'region' => 'eu-west-1',
                'owner_name' => 'Chris Adeyemi',
                'owner_email' => 'chris@orbit.store',
                'provisioned_at' => '2025-08-09',
                'subscription' => ['key' => 'sub_4', 'plan' => 'tx_enterprise', 'price' => 1200, 'renews' => 'Oct 9, 2026'],
                'extra_hosts' => [
                    ['host' => 'send.orbit.store', 'custom' => true, 'status' => 'pending_dns', 'ssl' => false],
                ],
            ],
            [
                'name' => 'Pixelcraft',
                'slug' => 'pixelcraft',
                'provider' => 'resend_staging',
                'status' => 'trial',
                'plan' => 'Free',
                'product' => 'transactional',
                'subdomain' => 'pixelcraft',
                'mrr' => 0,
                'seats' => 1,
                'emails_30d' => 88,
                'owner_name' => 'Lina Brooks',
                'owner_email' => 'lina@pixelcraft.co',
                'provisioned_at' => '2026-09-01',
                'host_status' => 'provisioning',
                'host_ssl' => false,
            ],
            [
                'name' => 'Summit Health',
                'slug' => 'summit-health',
                'provider' => 'sendgrid',
                'status' => 'suspended',
                'plan' => 'Pro',
                'product' => 'transactional',
                'subdomain' => 'summit',
                'mrr' => 0,
                'seats' => 8,
                'emails_30d' => 0,
                'owner_name' => 'Dr. Nia Okoro',
                'owner_email' => 'nia@summit.health',
                'provisioned_at' => '2025-12-04',
            ],
            [
                'name' => 'Demo Sandbox',
                'slug' => 'demo-sandbox',
                'provider' => 'resend_staging',
                'status' => 'trial',
                'plan' => 'Free',
                'product' => 'transactional',
                'subdomain' => 'demo',
                'mrr' => 0,
                'seats' => 1,
                'emails_30d' => 12,
                'owner_name' => 'Ade Tola',
                'owner_email' => 'demo@maildesk.test',
                'provisioned_at' => '2026-09-05',
            ],
            [
                'name' => 'Orphan Labs',
                'slug' => 'orphan-labs',
                'provider' => null,
                'status' => 'active',
                'plan' => 'Free',
                'product' => 'marketing',
                'subdomain' => 'orphan',
                'mrr' => 0,
                'seats' => 2,
                'emails_30d' => 0,
                'owner_name' => 'Casey Ng',
                'owner_email' => 'casey@orphan.dev',
                'provisioned_at' => '2026-09-02',
                'default_provider' => 'deleted_provider',
            ],
        ];

        foreach ($accounts as $row) {
            $providerKey = $row['provider'] ?? null;
            $org = Organization::query()->updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'name' => $row['name'],
                    'default_provider' => $row['default_provider'] ?? ($providerKey ?? 'resend'),
                    'status' => $row['status'],
                    'plan' => $row['plan'],
                    'product' => $row['product'],
                    'subdomain' => $row['subdomain'],
                    'custom_domain' => $row['custom_domain'] ?? null,
                    'mrr' => $row['mrr'],
                    'seats' => $row['seats'],
                    'emails_30d' => $row['emails_30d'],
                    'region' => $row['region'] ?? 'us-east-1',
                    'owner_name' => $row['owner_name'],
                    'owner_email' => $row['owner_email'],
                    'mail_provider_id' => $providerKey
                        ? ($providerModels[$providerKey]->id ?? null)
                        : null,
                    'provisioned_at' => $row['provisioned_at'],
                ],
            );

            $org->users()->syncWithoutDetaching([
                $admin->id => ['role' => 'owner'],
            ]);

            OrganizationHost::query()->updateOrCreate(
                ['host' => $row['subdomain'].'.maildesk.test'],
                [
                    'organization_id' => $org->id,
                    'subdomain' => $row['subdomain'],
                    'status' => $row['host_status'] ?? 'active',
                    'ssl' => $row['host_ssl'] ?? true,
                    'is_custom' => false,
                ],
            );

            foreach ($row['extra_hosts'] ?? [] as $extra) {
                OrganizationHost::query()->updateOrCreate(
                    ['host' => $extra['host']],
                    [
                        'organization_id' => $org->id,
                        'subdomain' => null,
                        'status' => $extra['status'],
                        'ssl' => $extra['ssl'],
                        'is_custom' => $extra['custom'],
                    ],
                );
            }

            if (isset($row['subscription'])) {
                $sub = $row['subscription'];
                $plan = $planModels[$sub['plan']] ?? null;
                Subscription::query()->updateOrCreate(
                    ['key' => $sub['key']],
                    [
                        'organization_id' => $org->id,
                        'plan_id' => $plan?->id,
                        'plan_name' => $plan?->name ?? $org->plan,
                        'product' => $org->product,
                        'status' => $sub['status'] ?? 'active',
                        'price' => $sub['price'],
                        'renews_at' => $sub['renews'],
                        'seats' => $org->seats,
                    ],
                );
            }
        }
    }
}
