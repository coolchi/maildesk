<?php

namespace Tests\Feature;

use App\Jobs\DetectWorkspaceAbuse;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\AbuseDetectionService;
use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AbuseDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['maildesk.ai.fake' => true]);
    }

    public function test_abuse_scan_endpoint_stores_signals_on_organization(): void
    {
        app(PlatformSettings::class)->set([
            'ai_enabled' => true,
            'ai_abuse_detection' => true,
            'ai_provider' => 'openai',
        ]);

        $user = User::factory()->create();
        $org = Organization::factory()->create(['status' => 'active']);
        $org->users()->attach($user->id, ['role' => 'owner']);

        for ($i = 0; $i < 60; $i++) {
            Message::factory()->create([
                'organization_id' => $org->id,
                'direction' => 'outbound',
                'status' => $i < 10 ? 'bounced' : 'delivered',
            ]);
        }

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->postJson(route('ai.abuse'))
            ->assertOk()
            ->assertJsonPath('severity', 'medium')
            ->assertJsonPath('flags.0.code', 'bounce_spike');

        $org->refresh();
        $this->assertSame('medium', data_get($org->settings, 'abuse.severity'));
    }

    public function test_detect_workspace_abuse_job_scans_active_orgs(): void
    {
        app(PlatformSettings::class)->set([
            'ai_enabled' => true,
            'ai_abuse_detection' => true,
            'ai_provider' => 'openai',
        ]);

        $org = Organization::factory()->create(['status' => 'active']);

        (new DetectWorkspaceAbuse)->handle(
            app(AbuseDetectionService::class),
            app(PlatformSettings::class),
        );

        $org->refresh();
        $this->assertSame('ok', data_get($org->settings, 'abuse.severity'));
    }
}
