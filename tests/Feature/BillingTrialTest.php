<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\ProviderConfig;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AccountAccess;
use App\Services\PlatformSettings;
use App\Services\TrialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BillingTrialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'maildesk.base_domain' => 'maildesk.test',
            'session.domain' => null,
            'maildesk.fake_send' => true,
        ]);
    }

    public function test_creating_a_workspace_starts_a_trial_subscription(): void
    {
        $user = User::factory()->create();
        app(PlatformSettings::class)->set(['trial_days' => 10]);

        $this->actingAs($user)
            ->post(route('workspaces.store'), [
                'name' => 'Acme Desk',
                'subdomain' => 'acme-desk',
            ])
            ->assertRedirect('/emails');

        $org = Organization::query()->where('name', 'Acme Desk')->first();

        $this->assertNotNull($org);
        $this->assertSame('trial', $org->status);
        $this->assertSame('Trial', $org->plan);

        $sub = $org->subscriptions()->first();
        $this->assertNotNull($sub);
        $this->assertSame('trial', $sub->status);
        $this->assertTrue($sub->current_period_ends_at->isAfter(now()->addDays(9)));
        $this->assertTrue($sub->current_period_ends_at->isBefore(now()->addDays(11)));
    }

    public function test_expired_trial_locks_inbox_but_allows_settings_billing(): void
    {
        $user = User::factory()->create();
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);
        $org = Organization::factory()->create([
            'status' => 'past_due',
            'plan' => 'Trial',
            'mail_provider_id' => $provider->id,
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);
        Subscription::factory()->create([
            'organization_id' => $org->id,
            'status' => 'past_due',
            'plan_name' => 'Trial',
            'current_period_ends_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('inbox'))
            ->assertRedirect(route('settings', 'billing'));

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('settings', 'billing'))
            ->assertOk();
    }

    public function test_expire_trials_command_marks_past_due(): void
    {
        $org = Organization::factory()->create(['status' => 'trial', 'plan' => 'Trial']);
        Subscription::factory()->create([
            'organization_id' => $org->id,
            'status' => 'trial',
            'plan_name' => 'Trial',
            'current_period_ends_at' => now()->subHour(),
        ]);

        Artisan::call('billing:expire-trials');

        $this->assertSame('past_due', $org->fresh()->status);
        $this->assertSame('past_due', $org->subscriptions()->first()->status);
    }

    public function test_active_plan_blocks_send_when_email_quota_exceeded(): void
    {
        $user = User::factory()->create();
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);
        $plan = Plan::factory()->create([
            'product' => 'transactional',
            'emails' => 2,
            'interval' => 'month',
            'price' => 20,
        ]);
        $org = Organization::factory()->create([
            'status' => 'active',
            'plan' => $plan->name,
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);
        Domain::factory()->verified()->create([
            'organization_id' => $org->id,
            'name' => 'acme.test',
        ]);
        $periodEnds = now()->addMonth();
        Subscription::factory()->create([
            'organization_id' => $org->id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'product' => 'transactional',
            'status' => 'active',
            'current_period_ends_at' => $periodEnds,
        ]);

        Message::factory()->count(2)->create([
            'organization_id' => $org->id,
            'direction' => 'outbound',
            'status' => 'sent',
            'created_at' => $periodEnds->copy()->subDays(5),
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->from(route('emails'))
            ->post(route('emails.store'), [
                'from' => 'hello@acme.test',
                'to' => 'cara@example.com',
                'subject' => 'Over quota',
                'html' => '<p>Hi</p>',
            ])
            ->assertRedirect(route('settings', 'billing'))
            ->assertSessionHas('error', AccountAccess::QUOTA_MESSAGE);
    }

    public function test_smtp_only_workspace_can_send_flag_is_true(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create([
            'status' => 'trial',
            'mail_provider_id' => null,
            'default_provider' => 'smtp',
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);
        app(TrialService::class)->start($org);

        ProviderConfig::query()->create([
            'organization_id' => $org->id,
            'provider' => 'smtp',
            'is_active' => true,
            'credentials' => [
                'host' => 'smtp.example.test',
                'port' => 587,
                'encryption' => 'tls',
            ],
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('settings', 'usage'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tenant.can_send', true));
    }
}
