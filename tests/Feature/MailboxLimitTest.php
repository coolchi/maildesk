<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MailboxLimit;
use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MailboxLimitTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['maildesk.stalwart.enabled' => false]);

        $this->owner = User::factory()->create();
        $this->org = Organization::factory()->create(['status' => 'active']);
        $this->org->users()->attach($this->owner->id, ['role' => 'owner']);
        Domain::factory()->verified()->create(['organization_id' => $this->org->id, 'name' => 'acme.test']);
    }

    private function onPlan(Organization $org, ?int $limit): Plan
    {
        $plan = Plan::factory()->create(['mailbox_limit' => $limit]);
        Subscription::factory()->create(['organization_id' => $org->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $plan;
    }

    private function mailboxes(Organization $org, int $count, string $domain = 'acme.test'): void
    {
        for ($i = 0; $i < $count; $i++) {
            Mailbox::factory()->create(['organization_id' => $org->id, 'email' => "user{$i}.{$org->id}@{$domain}"]);
        }
    }

    private function api()
    {
        $token = ApiKey::issue($this->org, 'Test', $this->owner)['plain'];

        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']);
    }

    private function webCreate(string $local)
    {
        return $this->actingAs($this->owner)
            ->withSession(['current_organization_id' => $this->org->id])
            ->post(route('users.store'), [
                'name' => 'Someone',
                'local' => $local,
                'domain' => 'acme.test',
                'role' => 'staff',
                'inbox' => true,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);
    }

    public function test_api_blocks_creation_at_the_plan_limit(): void
    {
        $this->onPlan($this->org, 2);
        $this->mailboxes($this->org, 1);

        $this->api()->postJson('/api/v1/mailboxes', ['email' => 'second@acme.test'])->assertCreated();

        $this->api()->postJson('/api/v1/mailboxes', ['email' => 'third@acme.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mailbox_limit')
            ->assertJsonPath('mailbox_limit', 2)
            ->assertJsonPath('mailboxes_used', 2)
            ->assertJsonPath('message', 'Your plan allows 2 email addresses and this workspace has 2. Upgrade your plan or delete an address to add another.');

        $this->assertDatabaseMissing('mailboxes', ['email' => 'third@acme.test']);
        $this->assertDatabaseMissing('users', ['email' => 'third@acme.test']);

        $this->api()->getJson('/api/v1/mailboxes')
            ->assertOk()
            ->assertJsonPath('mailbox_limit', 2)
            ->assertJsonPath('mailboxes_used', 2);
    }

    public function test_web_users_page_blocks_creation_at_the_plan_limit(): void
    {
        $this->onPlan($this->org, 1);

        $this->webCreate('first')->assertRedirect()->assertSessionHasNoErrors();
        $this->webCreate('second')->assertRedirect()->assertSessionHasErrors('local');

        $this->assertDatabaseHas('mailboxes', ['email' => 'first@acme.test']);
        $this->assertDatabaseMissing('mailboxes', ['email' => 'second@acme.test']);

        $this->actingAs($this->owner)
            ->withSession(['current_organization_id' => $this->org->id])
            ->get(route('users'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('mailboxLimit.limit', 1)
                ->where('mailboxLimit.used', 1)
                ->where('mailboxLimit.remaining', 0));
    }

    public function test_workspace_already_over_the_limit_keeps_its_mailboxes(): void
    {
        $this->onPlan($this->org, 1);
        $this->mailboxes($this->org, 3);

        $this->api()->postJson('/api/v1/mailboxes', ['email' => 'more@acme.test'])->assertUnprocessable();
        $this->assertSame(3, $this->org->mailboxes()->count());

        $usage = app(MailboxLimit::class)->usage($this->org);
        $this->assertSame(['limit' => 1, 'used' => 3, 'remaining' => 0], $usage);
    }

    public function test_null_plan_limit_and_no_subscription_are_unlimited(): void
    {
        $this->mailboxes($this->org, 5);
        $this->api()->postJson('/api/v1/mailboxes', ['email' => 'nosub@acme.test'])->assertCreated();

        $this->onPlan($this->org, null);
        $this->api()->postJson('/api/v1/mailboxes', ['email' => 'nulllimit@acme.test'])->assertCreated();

        $this->api()->getJson('/api/v1/mailboxes')->assertOk()->assertJsonPath('mailbox_limit', null);
    }

    public function test_other_workspaces_mailboxes_do_not_count(): void
    {
        $this->onPlan($this->org, 1);

        $other = Organization::factory()->create();
        Domain::factory()->verified()->create(['organization_id' => $other->id, 'name' => 'other.test']);
        $this->mailboxes($other, 4, 'other.test');

        $this->api()->postJson('/api/v1/mailboxes', ['email' => 'mine@acme.test'])->assertCreated();
        $this->assertSame(1, app(MailboxLimit::class)->used($this->org));
    }

    public function test_trial_without_a_plan_uses_the_platform_trial_limit(): void
    {
        $this->org->forceFill(['status' => 'trial'])->save();
        Subscription::factory()->create(['organization_id' => $this->org->id, 'plan_id' => null, 'status' => 'trial']);

        $limits = app(MailboxLimit::class);
        $this->assertNull($limits->limitFor($this->org), 'Blank setting means unlimited');

        app(PlatformSettings::class)->set(['trial_mailbox_limit' => 1]);
        $this->assertSame(1, app(MailboxLimit::class)->limitFor($this->org));

        $this->api()->postJson('/api/v1/mailboxes', ['email' => 'one@acme.test'])->assertCreated();
        $this->api()->postJson('/api/v1/mailboxes', ['email' => 'two@acme.test'])
            ->assertUnprocessable()
            ->assertJsonPath('mailbox_limit', 1);
    }

    public function test_paid_plan_limit_wins_over_the_trial_setting(): void
    {
        app(PlatformSettings::class)->set(['trial_mailbox_limit' => 1]);
        $this->onPlan($this->org, 5);

        $this->assertSame(5, app(MailboxLimit::class)->limitFor($this->org));
    }

    public function test_admin_can_set_a_plan_mailbox_limit(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $plan = Plan::factory()->create(['name' => 'Pro', 'price' => 20, 'mailbox_limit' => null]);

        $this->actingAs($admin)->put(route('admin.plans.update', $plan), [
            'name' => 'Pro',
            'price' => 20,
            'mailbox_limit' => 25,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(25, $plan->fresh()->mailbox_limit);
        $this->assertSame(25, $plan->fresh()->toAdminArray()['mailbox_limit']);

        $this->actingAs($admin)->put(route('admin.plans.update', $plan), [
            'name' => 'Pro',
            'price' => 20,
            'mailbox_limit' => null,
        ])->assertRedirect();

        $this->assertNull($plan->fresh()->mailbox_limit);
    }
}
