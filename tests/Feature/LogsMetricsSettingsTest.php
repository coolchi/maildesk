<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogsMetricsSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_only_include_current_workspace_messages(): void
    {
        $user = User::factory()->create();
        $own = Organization::factory()->create();
        $other = Organization::factory()->create();
        $own->users()->attach($user->id, ['role' => 'owner']);

        Message::factory()->create([
            'organization_id' => $own->id,
            'subject' => 'Own subject',
            'status' => 'sent',
            'direction' => 'outbound',
        ]);
        Message::factory()->create([
            'organization_id' => $other->id,
            'subject' => 'Other subject',
            'status' => 'sent',
            'direction' => 'outbound',
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $own->id])
            ->get(route('logs'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Logs/Index')
                ->has('logs', 1)
                ->where('logs.0.message', fn ($m) => str_contains($m, 'Own subject')));
    }

    public function test_metrics_return_stats_and_series(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        Message::factory()->create([
            'organization_id' => $org->id,
            'direction' => 'outbound',
            'status' => 'sent',
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('metrics'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Metrics/Index')
                ->has('stats')
                ->has('series')
                ->where('stats.sent', 1));
    }

    public function test_settings_can_update_unsubscribe(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create(['settings' => []]);
        $org->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->put(route('settings.update'), [
                'unsubscribe' => [
                    'brand' => 'Harbor',
                    'headline' => 'Bye',
                ],
            ])
            ->assertRedirect();

        $org->refresh();
        $this->assertSame('Harbor', $org->settings['unsubscribe']['brand']);
        $this->assertSame('Bye', $org->settings['unsubscribe']['headline']);
    }
}
