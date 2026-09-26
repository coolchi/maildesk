<?php

namespace Tests\Feature;

use App\Jobs\SendBroadcast;
use App\Jobs\SendScheduledBroadcast;
use App\Models\Broadcast;
use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccountAccess;
use App\Services\BroadcastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BroadcastScheduleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Organization}
     */
    private function workspace(): array
    {
        $user = User::factory()->create();
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);
        $org = Organization::factory()->create([
            'status' => 'active',
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $org];
    }

    public function test_broadcast_can_be_scheduled_and_cancelled(): void
    {
        [$user, $org] = $this->workspace();
        $when = now()->addHour()->seconds(0);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('broadcasts.store'), [
                'name' => 'Later',
                'subject' => 'Hello later',
                'html' => '<p>Hi</p>',
                'segment' => 'all',
                'send_now' => false,
                'scheduled_at' => $when->toIso8601String(),
            ])
            ->assertRedirect();

        $broadcast = Broadcast::query()->firstOrFail();
        $this->assertSame('scheduled', $broadcast->status);
        $this->assertNotNull($broadcast->scheduled_at);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('broadcasts.cancel', $broadcast))
            ->assertRedirect();

        $broadcast->refresh();
        $this->assertSame('draft', $broadcast->status);
        $this->assertNull($broadcast->scheduled_at);
    }

    public function test_scheduled_broadcast_job_queues_due_broadcasts(): void
    {
        Queue::fake();
        [, $org] = $this->workspace();

        $due = Broadcast::query()->create([
            'organization_id' => $org->id,
            'name' => 'Due',
            'subject' => 'Now',
            'html' => '<p>x</p>',
            'audience' => 'all',
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
        ]);

        Broadcast::query()->create([
            'organization_id' => $org->id,
            'name' => 'Later',
            'subject' => 'Wait',
            'html' => '<p>x</p>',
            'audience' => 'all',
            'status' => 'scheduled',
            'scheduled_at' => now()->addHour(),
        ]);

        (new SendScheduledBroadcast)->handle(app(BroadcastService::class), app(AccountAccess::class));

        $due->refresh();
        $this->assertSame('queued', $due->status);
        Queue::assertPushed(SendBroadcast::class, fn ($job) => $job->broadcastId === $due->id);
    }
}
