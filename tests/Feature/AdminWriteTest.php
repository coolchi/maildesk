<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationHost;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_suspend_and_reactivate_account(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $org = Organization::factory()->create(['status' => 'active']);

        $this->actingAs($admin)
            ->put(route('admin.accounts.status', $org), ['status' => 'suspended'])
            ->assertRedirect();

        $this->assertDatabaseHas('organizations', [
            'id' => $org->id,
            'status' => 'suspended',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.accounts.status', $org), ['status' => 'active'])
            ->assertRedirect();

        $this->assertDatabaseHas('organizations', [
            'id' => $org->id,
            'status' => 'active',
        ]);
    }

    public function test_admin_can_update_account_hosts(): void
    {
        config(['maildesk.base_domain' => 'maildesk.test']);

        $admin = User::factory()->platformAdmin()->create();
        $org = Organization::factory()->create([
            'subdomain' => 'oldco',
            'custom_domain' => null,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.accounts.hosts', $org), [
                'subdomain' => 'newco',
                'custom_domain' => 'mail.newco.test',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('organizations', [
            'id' => $org->id,
            'subdomain' => 'newco',
            'custom_domain' => 'mail.newco.test',
        ]);

        $this->assertDatabaseHas('organization_hosts', [
            'organization_id' => $org->id,
            'host' => 'newco.maildesk.test',
            'is_custom' => false,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('organization_hosts', [
            'organization_id' => $org->id,
            'host' => 'mail.newco.test',
            'is_custom' => true,
            'status' => 'pending_dns',
        ]);
    }

    public function test_admin_can_create_and_verify_host(): void
    {
        config(['maildesk.base_domain' => 'maildesk.test']);

        $admin = User::factory()->platformAdmin()->create();
        $org = Organization::factory()->create(['subdomain' => 'acme']);

        $this->actingAs($admin)
            ->post(route('admin.subdomains.store'), [
                'organization_id' => $org->id,
                'type' => 'custom',
                'host' => 'send.acme.test',
            ])
            ->assertRedirect();

        $host = OrganizationHost::query()->where('host', 'send.acme.test')->first();
        $this->assertNotNull($host);
        $this->assertSame('pending_dns', $host->status);

        $this->actingAs($admin)
            ->post(route('admin.subdomains.verify', $host))
            ->assertRedirect();

        $this->assertDatabaseHas('organization_hosts', [
            'id' => $host->id,
            'status' => 'active',
            'ssl' => true,
        ]);
    }

    public function test_admin_can_update_plan(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $plan = Plan::factory()->create([
            'key' => 'tx_pro',
            'name' => 'Pro',
            'price' => 20,
            'featured' => true,
        ]);
        $org = Organization::factory()->create(['plan' => 'Pro']);
        Subscription::factory()->create([
            'organization_id' => $org->id,
            'plan_id' => $plan->id,
            'plan_name' => 'Pro',
            'price' => 20,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.plans.update', $plan), [
                'name' => 'Pro Plus',
                'price' => 29,
                'featured' => false,
                'features' => [
                    ['id' => 'f1', 'label' => 'More emails', 'included' => true],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'name' => 'Pro Plus',
            'price' => 29,
            'featured' => false,
        ]);

        $this->assertDatabaseHas('subscriptions', [
            'plan_id' => $plan->id,
            'plan_name' => 'Pro Plus',
            'price' => 29,
        ]);

        $this->assertDatabaseHas('organizations', [
            'id' => $org->id,
            'plan' => 'Pro Plus',
        ]);
    }

    public function test_admin_can_update_subscription(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $plan = Plan::factory()->create([
            'key' => 'tx_pro',
            'name' => 'Pro',
            'price' => 20,
            'product' => 'transactional',
        ]);
        $next = Plan::factory()->create([
            'key' => 'tx_enterprise',
            'name' => 'Enterprise',
            'price' => 99,
            'product' => 'transactional',
        ]);
        $org = Organization::factory()->create(['plan' => 'Pro', 'product' => 'transactional']);
        $subscription = Subscription::factory()->create([
            'organization_id' => $org->id,
            'plan_id' => $plan->id,
            'plan_name' => 'Pro',
            'price' => 20,
            'status' => 'active',
            'product' => 'transactional',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.subscriptions.update', $subscription), [
                'plan_id' => $next->id,
                'status' => 'past_due',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'plan_id' => $next->id,
            'plan_name' => 'Enterprise',
            'status' => 'past_due',
            'price' => 99,
        ]);

        $this->assertDatabaseHas('organizations', [
            'id' => $org->id,
            'plan' => 'Enterprise',
            'mrr' => 0,
        ]);
    }

    public function test_non_admin_cannot_write_admin_resources(): void
    {
        $user = User::factory()->create(['is_platform_admin' => false]);
        $org = Organization::factory()->create();
        $plan = Plan::factory()->create();
        $subscription = Subscription::factory()->create([
            'organization_id' => $org->id,
            'plan_id' => $plan->id,
        ]);

        $this->actingAs($user)
            ->put(route('admin.accounts.status', $org), ['status' => 'suspended'])
            ->assertForbidden();

        $this->actingAs($user)
            ->put(route('admin.plans.update', $plan), [
                'name' => 'Nope',
                'price' => 1,
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->put(route('admin.subscriptions.update', $subscription), [
                'status' => 'canceled',
            ])
            ->assertForbidden();
    }
}
