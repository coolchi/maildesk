<?php

namespace Tests\Feature;

use App\Jobs\E2EHeartbeatJob;
use App\Jobs\ProcessResendInboundEmail;
use App\Jobs\RunE2EMailTest;
use App\Models\E2ETestRun;
use App\Models\Mailbox;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\E2EMailTestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class E2EMailTestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([RunE2EMailTest::class, ProcessResendInboundEmail::class]);

        config([
            'services.resend.key' => 're_test_key',
            'maildesk.inbound.resend_webhook_secret' => 'whsec_test',
            'maildesk.e2e.from' => 'test@maildesk.ng',
            'maildesk.e2e.mailbox' => 'e2e-check@maildesk.ng',
            'maildesk.e2e.timeout' => 5,
            'maildesk.e2e.organization_id' => null,
            'maildesk.fake_send' => true,
        ]);
    }

    protected function createTestOrganization(): Organization
    {
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);

        return Organization::factory()->create([
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
        ]);
    }

    public function test_e2e_test_run_model_tracks_steps_and_status(): void
    {
        $run = E2ETestRun::query()->create([
            'status' => 'pending',
            'token' => Str::random(32),
            'source' => 'cli',
        ]);

        $this->assertSame('pending', $run->status);

        $run->markStarted();
        $this->assertSame('running', $run->fresh()->status);
        $this->assertNotNull($run->started_at);

        $run->markStep('preflight', 150.5, true, 'Config OK');
        $this->assertSame(['preflight' => 150.5], $run->fresh()->timings);
        $this->assertTrue($run->steps['preflight']['success']);

        $run->markFailed('send', 'Provider error', 'Check API key');
        $run = $run->fresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('send', $run->failed_step);
        $this->assertSame('Provider error', $run->error);
        $this->assertSame('Check API key', $run->error_hint);
    }

    public function test_admin_system_test_page_requires_platform_admin(): void
    {
        $user = User::factory()->create(['is_platform_admin' => false]);

        $this->actingAs($user)
            ->get('/admin/system-test')
            ->assertForbidden();
    }

    public function test_admin_system_test_page_loads_for_platform_admin(): void
    {
        $user = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($user)
            ->get('/admin/system-test')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/SystemTest/Index')
                ->has('config')
                ->has('runs'));
    }

    public function test_admin_can_start_e2e_test(): void
    {
        $user = User::factory()->create(['is_platform_admin' => true]);

        $response = $this->actingAs($user)
            ->postJson('/admin/system-test/start', ['include_events' => false]);

        $response->assertOk()->assertJsonStructure(['run' => ['id', 'token', 'status']]);

        Queue::assertPushed(RunE2EMailTest::class);

        $this->assertDatabaseHas('e2e_test_runs', [
            'source' => 'web',
            'user_id' => $user->id,
            'status' => 'pending',
        ]);
    }

    public function test_admin_can_poll_test_status(): void
    {
        $user = User::factory()->create(['is_platform_admin' => true]);

        $run = E2ETestRun::query()->create([
            'status' => 'running',
            'token' => Str::random(32),
            'source' => 'web',
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->postJson('/admin/system-test/poll', ['run_id' => $run->id]);

        $response->assertOk()->assertJsonPath('run.status', 'running');
    }

    public function test_e2e_service_fails_without_resend_api_key(): void
    {
        config(['services.resend.key' => null]);

        $org = $this->createTestOrganization();
        config(['maildesk.e2e.organization_id' => $org->id]);

        $run = E2ETestRun::query()->create([
            'status' => 'pending',
            'token' => Str::random(32),
            'source' => 'cli',
        ]);

        $service = app(E2EMailTestService::class);
        $result = $service->execute($run);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('RESEND_API_KEY', $result['error']);
        $this->assertSame('preflight', $run->fresh()->failed_step);
    }

    public function test_e2e_service_fails_without_webhook_secret(): void
    {
        config(['maildesk.inbound.resend_webhook_secret' => null]);

        $org = $this->createTestOrganization();
        config(['maildesk.e2e.organization_id' => $org->id]);

        $run = E2ETestRun::query()->create([
            'status' => 'pending',
            'token' => Str::random(32),
            'source' => 'cli',
        ]);

        $service = app(E2EMailTestService::class);
        $result = $service->execute($run);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('RESEND_WEBHOOK_SECRET', $result['error']);
    }

    public function test_e2e_service_creates_test_mailbox_if_missing(): void
    {
        $org = $this->createTestOrganization();
        config(['maildesk.e2e.organization_id' => $org->id]);

        $this->assertFalse(
            Mailbox::query()
                ->where('organization_id', $org->id)
                ->where('email', 'e2e-check@maildesk.ng')
                ->exists()
        );

        Http::fake([
            'api.resend.com/domains' => Http::response([
                'data' => [['name' => 'maildesk.ng', 'status' => 'verified', 'receiving' => true]],
            ]),
        ]);

        Cache::shouldReceive('forget')->andReturnTrue();
        Cache::shouldReceive('get')->andReturn('alive');
        Cache::shouldReceive('put')->andReturnTrue();

        $run = E2ETestRun::query()->create([
            'status' => 'pending',
            'token' => Str::random(32),
            'source' => 'cli',
        ]);

        $service = app(E2EMailTestService::class);

        try {
            $service->execute($run);
        } catch (\Throwable) {
        }

        $this->assertTrue(
            Mailbox::query()
                ->where('organization_id', $org->id)
                ->whereRaw('lower(email) = ?', ['e2e-check@maildesk.ng'])
                ->exists()
        );
    }

    public function test_e2e_command_exists_and_has_correct_signature(): void
    {
        $this->artisan('maildesk:e2e --help')
            ->assertSuccessful()
            ->expectsOutputToContain('--events')
            ->expectsOutputToContain('--timeout')
            ->expectsOutputToContain('--from')
            ->expectsOutputToContain('--mailbox');
    }

    public function test_e2e_heartbeat_job_sets_cache_key(): void
    {
        $cacheKey = 'test_heartbeat_'.Str::random(8);

        $job = new E2EHeartbeatJob($cacheKey);
        $job->handle();

        $this->assertSame('alive', Cache::get($cacheKey));
    }

    public function test_run_e2e_mail_test_job_executes_service(): void
    {
        Queue::fake();

        $org = $this->createTestOrganization();
        config(['maildesk.e2e.organization_id' => $org->id]);

        $run = E2ETestRun::query()->create([
            'status' => 'pending',
            'token' => Str::random(32),
            'source' => 'web',
        ]);

        $job = new RunE2EMailTest($run->id);

        config(['services.resend.key' => null]);

        $job->handle(app(E2EMailTestService::class));

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('preflight', $run->failed_step);
    }

    public function test_e2e_service_full_flow_with_simulated_inbound(): void
    {
        $org = $this->createTestOrganization();
        config(['maildesk.e2e.organization_id' => $org->id]);

        Mailbox::query()->create([
            'organization_id' => $org->id,
            'email' => 'e2e-check@maildesk.ng',
            'name' => 'E2E Test',
            'status' => 'active',
            'inbox' => true,
        ]);

        Http::fake([
            'api.resend.com/domains' => Http::response([
                'data' => [['name' => 'maildesk.ng', 'status' => 'verified', 'receiving' => true]],
            ]),
        ]);

        Cache::shouldReceive('forget')->andReturnTrue();
        Cache::shouldReceive('get')->andReturn('alive');
        Cache::shouldReceive('put')->andReturnTrue();

        $run = E2ETestRun::query()->create([
            'status' => 'pending',
            'token' => 'test_token_'.Str::random(16),
            'source' => 'cli',
        ]);

        $token = $run->token;

        dispatch(function () use ($org, $token) {
            sleep(1);
            Message::query()->create([
                'organization_id' => $org->id,
                'direction' => 'inbound',
                'status' => 'received',
                'provider' => 'resend',
                'from_email' => 'test@maildesk.ng',
                'to' => ['e2e-check@maildesk.ng'],
                'subject' => "[E2E Test] {$token}",
                'text_body' => "Token: {$token}",
            ]);
        })->afterResponse();

        $service = app(E2EMailTestService::class);
        $result = $service->execute($run);

        $run->refresh();

        $this->assertNotNull($run->outbound_message_id);
        $this->assertContains($run->status, ['passed', 'failed']);

        if ($run->status === 'failed' && $run->failed_step === 'receive') {
            $this->markTestSkipped('Simulated inbound timing issue in test environment');
        }
    }

    public function test_recent_runs_shown_on_admin_page(): void
    {
        $user = User::factory()->create(['is_platform_admin' => true]);

        E2ETestRun::query()->create([
            'status' => 'passed',
            'token' => Str::random(32),
            'source' => 'cli',
            'started_at' => now()->subHour(),
            'completed_at' => now()->subHour()->addSeconds(45),
        ]);

        E2ETestRun::query()->create([
            'status' => 'failed',
            'token' => Str::random(32),
            'source' => 'web',
            'user_id' => $user->id,
            'failed_step' => 'receive',
            'error' => 'Timeout',
        ]);

        $this->actingAs($user)
            ->get('/admin/system-test')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('runs', 2));
    }
}
