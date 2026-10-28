<?php

namespace Tests\Feature;

use App\Models\ConversationParticipant;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatLeaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_removes_the_chat_for_that_user_only(): void
    {
        [$user, $organization, $token] = $this->member();
        $other = User::factory()->create(['name' => 'Ada']);
        $organization->users()->attach($other->id, ['role' => 'member']);
        $otherToken = $other->createToken('phone')->plainTextToken;

        $conversationId = $this->asApp($token, $organization)->postJson('/api/app/conversations', [
            'type' => 'direct',
            'user_ids' => [$other->id],
        ])->json('data.id');

        $this->asApp($token, $organization)
            ->deleteJson("/api/app/conversations/{$conversationId}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->asApp($token, $organization)->getJson('/api/app/conversations')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->asApp($otherToken, $organization)->getJson('/api/app/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversationId);

        $this->assertDatabaseMissing('conversation_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $other->id,
        ]);
    }

    public function test_reopening_a_direct_chat_restores_membership(): void
    {
        [$user, $organization, $token] = $this->member();
        $other = User::factory()->create(['name' => 'Grace']);
        $organization->users()->attach($other->id, ['role' => 'member']);

        $conversationId = $this->asApp($token, $organization)->postJson('/api/app/conversations', [
            'type' => 'direct',
            'user_ids' => [$other->id],
        ])->json('data.id');

        $this->asApp($token, $organization)
            ->deleteJson("/api/app/conversations/{$conversationId}")
            ->assertOk();

        $this->assertSame(0, ConversationParticipant::query()->where('user_id', $user->id)->count());

        $restored = $this->asApp($token, $organization)->postJson('/api/app/conversations', [
            'type' => 'direct',
            'user_ids' => [$other->id],
        ])->assertOk()
            ->json('data.id');

        $this->assertSame($conversationId, $restored);
        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $user->id,
        ]);
    }

    /**
     * @return array{0: User, 1: Organization, 2: string}
     */
    private function member(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $organization, $user->createToken('test')->plainTextToken];
    }

    private function asApp(string $token, Organization $organization): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->withHeader('X-Organization-Id', (string) $organization->id);
    }
}
