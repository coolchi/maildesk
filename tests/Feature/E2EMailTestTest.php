<?php

namespace Tests\Feature;

use App\Jobs\E2EHeartbeatJob;
use App\Jobs\ProcessResendInboundEmail;
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

        Queue::fake([ProcessResendInboundEmail::class]);

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

    public function test_admin_can_start_e2e_test_synchronously(): void
    {
        $user = User::factory()->create(['is_platform_admin' => true]);
        $org = $this->createTestOrganization();
        config(['maildesk.e2e.organization_id' => $org->id]);

        Http::fake([
            'api.resend.com/domains' => Http::response([
                'data' => [['name' => 'maildesk.ng', 'status' => 'verified']],
            ]),
        ]);

        Cache::shouldReceive('forget')->andReturnTrue();
        Cache::shouldReceive('put')->andReturnTrue();
        Cache::shouldReceive('get')->andReturn('alive');

        $response = $this->actingAs($user)
            ->postJson('/admin/system-test/start', ['include_events' => false]);

        $response->assertOk()->assertJsonStructure(['run' => ['id', 'token', 'status'], 'phase']);

        $this->assertDatabaseHas('e2e_test_runs', [
            'source' => 'web',
            'user_id' => $user->id,
        ]);
    }

    public function test_admin_poll_advances_test_run(): void
    {
        $user = User::factory()->create(['is_platform_admin' => true]);
        $org = $this->createTestOrganization();
        config(['maildesk.e2e.organization_id' => $org->id]);

        $run = E2ETestRun::query()->create([
            'status' => 'running',
            'token' => 'test_token_'.Str::random(16),
            'source' => 'web',
            'user_id' => $user->id,
            'started_at' => now(),
            'steps' => [
                'preflight' => ['success' => true],
                'heartbeat' => ['success' => true],
                'send' => ['success' => true],
                'receive_start' => ['started_at' => now()->toIso8601String()],
            ],
            'outbound_message_id' => null,
        ]);

        $response = $this->actingAs($user)
            ->postJson('/admin/system-test/poll', ['run_id' => $run->id]);

        $response->assertOk()
            ->assertJsonStructure(['run', 'phase', 'complete']);
    }

    public function test_poll_completes_test_when_inbound_message_arrives(): void
    {
        $user = User::factory()->create(['is_platform_admin' => true]);
        $org = $this->createTestOrganization();
        config(['maildesk.e2e.organization_id' => $org->id]);

        $token = 'completion_test_'.Str::random(16);

        $run = E2ETestRun::query()->create([
            'status' => 'running',
            'token' => $token,
            'source' => 'web',
            'user_id' => $user->id,
            'started_at' => now(),
            'steps' => [
                'preflight' => ['success' => true],
                'heartbeat' => ['success' => true],
                'send' => ['success' => true],
                'receive_start' => ['started_at' => now()->toIso8601String()],
            ],
        ]);

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

        $response = $this->actingAs($user)
            ->postJson('/admin/system-test/poll', ['run_id' => $run->id]);

        $response->assertOk()
            ->assertJsonPath('complete', true)
            ->assertJsonPath('phase', 'passed');

        $this->assertSame('passed', $run->fresh()->status);
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
        $result = $service->start($run);

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
        $result = $service->start($run);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('RESEND_WEBHOOK_SECRET', $result['error']);
    }

    public function test_e2e_service_fails_without_organization_id(): void
    {
        config(['maildesk.e2e.organization_id' => null]);

        $run = E2ETestRun::query()->create([
            'status' => 'pending',
            'token' => Str::random(32),
            'source' => 'cli',
        ]);

        $service = app(E2EMailTestService::class);
        $result = $service->start($run);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('MAILDESK_E2E_ORGANIZATION_ID', $result['error']);
        $this->assertStringContainsString('dedicated internal workspace', $result['hint']);
        $this->assertSame('preflight', $run->fresh()->failed_step);
    }

    public function test_e2e_service_fails_with_nonexistent_organization(): void
    {
        config(['maildesk.e2e.organization_id' => 99999]);

        $run = E2ETestRun::query()->create([
            'status' => 'pending',
            'token' => Str::random(32),
            'source' => 'cli',
        ]);

        $service = app(E2EMailTestService::class);
        $result = $service->start($run);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['error']);
        $this->assertSame('preflight', $run->fresh()->failed_step);
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
                'data' => [['name' => 'maildesk.ng', 'status' => 'verified']],
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
            $service->start($run);
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

    public function test_poll_endpoint_requires_platform_admin(): void
    {
        $user = User::factory()->create(['is_platform_admin' => false]);

        $run = E2ETestRun::query()->create([
            'status' => 'running',
            'token' => Str::random(32),
            'source' => 'web',
        ]);

        $this->actingAs($user)
            ->postJson('/admin/system-test/poll', ['run_id' => $run->id])
            ->assertForbidden();
    }

    public function test_domain_check_accepts_verified_status(): void
    {
        $org = $this->createTestOrganization();
        config(['maildesk.e2e.organization_id' => $org->id]);

        Http::fake([
            'api.resend.com/domains' => Http::response([
                'data' => [[
                    'id' => 'dom_123',
                    'name' => 'maildesk.ng',
                    'status' => 'verified',
                    'created_at' => '2024-01-01T00:00:00.000Z',
                    'region' => 'us-east-1',
                ]],
            ]),
        ]);

        Cache::shouldReceive('forget')->andReturnTrue();
        Cache::shouldReceive('put')->andReturnTrue();
        Cache::shouldReceive('get')->andReturn('alive');

        $run = E2ETestRun::query()->create([
            'status' => 'pending',
            'token' => Str::random(32),
            'source' => 'cli',
        ]);

        $service = app(E2EMailTestService::class);
        $result = $service->start($run);

        $this->assertNotSame('preflight', $run->fresh()->failed_step);
    }

    public function test_domain_check_accepts_partially_verified_status(): void
    {
        $org = $this->createTestOrganization();
        config(['maildesk.e2e.organization_id' => $org->id]);

        Http::fake([
            'api.resend.com/domains' => Http::response([
                'data' => [[
                    'id' => 'dom_456',
                    'name' => 'maildesk.ng',
                    'status' => 'partially_verified',
                    'created_at' => '2024-01-01T00:00:00.000Z',
                    'region' => 'eu-west-1',
                ]],
            ]),
        ]);

        Cache::shouldReceive('forget')->andReturnTrue();
        Cache::shouldReceive('put')->andReturnTrue();
        Cache::shouldReceive('get')->andReturn('alive');

        $run = E2ETestRun::query()->create([
            'status' => 'pending',
            'token' => Str::random(32),
            'source' => 'cli',
        ]);

        $service = app(E2EMailTestService::class);
        $result = $service->start($run);

        $this->assertNotSame('preflight', $run->fresh()->failed_step);
    }

    public function test_domain_check_accepts_domain_with_mx_record(): void
    {
        $org = $this->createTestOrganization();
        config(['maildesk.e2e.organization_id' => $org->id]);

        Http::fake([
            'api.resend.com/domains' => Http::response([
                'data' => [[
                    'id' => 'dom_789',
                    'name' => 'maildesk.ng',
                    'status' => 'pending',
                    'records' => [
                        ['record' => 'SPF', 'name' => 'maildesk.ng', 'type' => 'TXT', 'value' => 'v=spf1 include:resend.com ~all'],
                        ['record' => 'MX', 'name' => 'maildesk.ng', 'type' => 'MX', 'value' => '10 inbound.resend.com', 'priority' => 10],
                        ['record' => 'DKIM', 'name' => 'resend._domainkey.maildesk.ng', 'type' => 'TXT', 'value' => 'p=MIGf...'],
                    ],
                ]],
            ]),
        ]);

        Cache::shouldReceive('forget')->andReturnTrue();
        Cache::shouldReceive('put')->andReturnTrue();
        Cache::shouldReceive('get')->andReturn('alive');

        $run = E2ETestRun::query()->create([
            'status' => 'pending',
            'token' => Str::random(32),
            'source' => 'cli',
        ]);

        $service = app(E2EMailTestService::class);
        $result = $service->start($run);

        $this->assertNotSame('preflight', $run->fresh()->failed_step);
    }

    public function test_domain_check_fails_for_pending_domain_without_mx(): void
    {
        $org = $this->createTestOrganization();
        config(['maildesk.e2e.organization_id' => $org->id]);

        Http::fake([
            'api.resend.com/domains' => Http::response([
                'data' => [[
                    'id' => 'dom_abc',
                    'name' => 'maildesk.ng',
                    'status' => 'pending',
                    'records' => [
                        ['record' => 'SPF', 'name' => 'maildesk.ng', 'type' => 'TXT', 'value' => 'v=spf1 include:resend.com ~all'],
                    ],
                ]],
            ]),
        ]);

        Cache::shouldReceive('forget')->andReturnTrue();
        Cache::shouldReceive('put')->andReturnTrue();

        $run = E2ETestRun::query()->create([
            'status' => 'pending',
            'token' => Str::random(32),
            'source' => 'cli',
        ]);

        $service = app(E2EMailTestService::class);
        $result = $service->start($run);

        $this->assertFalse($result['success']);
        $this->assertSame('preflight', $run->fresh()->failed_step);
        $this->assertStringContainsString('not verified', $result['error']);
    }

    public function test_single_worker_flow_advances_via_poll_with_sync_queue(): void
    {
        $user = User::factory()->create(['is_platform_admin' => true]);
        $org = $this->createTestOrganization();
        config(['maildesk.e2e.organization_id' => $org->id]);

        Http::fake([
            'api.resend.com/domains' => Http::response([
                'data' => [['name' => 'maildesk.ng', 'status' => 'verified']],
            ]),
        ]);

        Cache::shouldReceive('forget')->andReturnTrue();
        Cache::shouldReceive('put')->andReturnTrue();
        Cache::shouldReceive('get')->andReturn('alive');

        $startResponse = $this->actingAs($user)
            ->postJson('/admin/system-test/start', ['include_events' => false]);

        $startResponse->assertOk();
        $runData = $startResponse->json('run');
        $runId = $runData['id'];
        $token = $runData['token'];

        $this->assertSame('running', $runData['status']);

        $pollResponse1 = $this->actingAs($user)
            ->postJson('/admin/system-test/poll', ['run_id' => $runId]);

        $pollResponse1->assertOk();
        $this->assertFalse($pollResponse1->json('complete'));

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

        $pollResponse2 = $this->actingAs($user)
            ->postJson('/admin/system-test/poll', ['run_id' => $runId]);

        $pollResponse2->assertOk();
        $this->assertTrue($pollResponse2->json('complete'));
        $this->assertSame('passed', $pollResponse2->json('run.status'));
    }

    public function test_admin_page_shows_organization_id_config(): void
    {
        $user = User::factory()->create(['is_platform_admin' => true]);
        $org = $this->createTestOrganization();
        config(['maildesk.e2e.organization_id' => $org->id]);

        $this->actingAs($user)
            ->get('/admin/system-test')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/SystemTest/Index')
                ->where('config.hasOrganizationId', true)
                ->where('config.organizationId', $org->id));
    }

    public function test_admin_page_shows_missing_organization_id(): void
    {
        $user = User::factory()->create(['is_platform_admin' => true]);
        config(['maildesk.e2e.organization_id' => null]);

        $this->actingAs($user)
            ->get('/admin/system-test')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/SystemTest/Index')
                ->where('config.hasOrganizationId', false));
    }
}
