<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Chat\PresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatPresenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_workspace_request_marks_the_member_as_online(): void
    {
        [$user, $organization, $token] = $this->member();
        $other = User::factory()->create(['last_seen_at' => now()->subHours(2)]);
        $organization->users()->attach($other->id, ['role' => 'member']);

        $this->asApp($token, $organization)->postJson('/api/app/conversations', [
            'type' => 'direct',
            'user_ids' => [$other->id],
        ])->assertCreated();

        $participants = collect($this->asApp($token, $organization)->getJson('/api/app/conversations')->json('data.0.participants'));

        $self = $participants->firstWhere('id', $user->id);
        $peer = $participants->firstWhere('id', $other->id);

        $this->assertTrue($self['online']);
        $this->assertNotNull($self['last_seen_at']);
        $this->assertFalse($peer['online']);
        $this->assertNotNull($peer['last_seen_at']);
    }

    public function test_a_recently_active_member_shows_as_online_in_chat(): void
    {
        [, $organization, $token] = $this->member();
        $other = User::factory()->create(['last_seen_at' => now()->subMinute()]);
        $organization->users()->attach($other->id, ['role' => 'member']);

        $this->asApp($token, $organization)->postJson('/api/app/conversations', [
            'type' => 'direct',
            'user_ids' => [$other->id],
        ])->assertCreated();

        $peer = collect($this->asApp($token, $organization)->getJson('/api/app/conversations')->json('data.0.participants'))
            ->firstWhere('id', $other->id);

        $this->assertTrue($peer['online']);
    }

    public function test_presence_service_keeps_last_seen_writes_light(): void
    {
        $user = User::factory()->create(['last_seen_at' => now()->subSeconds(10)]);
        $presence = app(PresenceService::class);

        $before = $user->fresh()->last_seen_at;
        $presence->touch($user->fresh());
        $this->assertTrue($user->fresh()->last_seen_at->equalTo($before));

        $user->forceFill(['last_seen_at' => now()->subMinutes(5)])->save();
        $presence->touch($user->fresh());
        $this->assertTrue($user->fresh()->last_seen_at->greaterThan($before));
        $this->assertTrue($presence->isOnline($user->fresh()->last_seen_at));
    }

    /**
     * @return array{0: User, 1: Organization, 2: string}
     */
    private function member(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $organization, $user->createToken('phone')->plainTextToken];
    }

    private function asApp(string $token, Organization $organization): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->withHeader('X-Organization-Id', (string) $organization->id);
    }
}
