<?php

namespace Tests\Feature;

use App\Jobs\SendChatPush;
use App\Models\ChatMessage;
use App\Models\DeviceToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatMuteArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_muted_chats_skip_push_and_stay_in_the_list(): void
    {
        Http::fake([
            'fcm.googleapis.com/*' => Http::response(['success' => 1]),
        ]);
        config(['services.fcm.server_key' => 'test-key']);

        [$user, $organization, $token] = $this->member();
        $other = User::factory()->create(['name' => 'Grace']);
        $organization->users()->attach($other->id, ['role' => 'member']);
        DeviceToken::query()->create([
            'user_id' => $other->id,
            'token' => 'grace-phone',
            'platform' => 'ios',
        ]);

        $conversationId = $this->asApp($token, $organization)->postJson('/api/app/conversations', [
            'type' => 'direct',
            'user_ids' => [$other->id],
        ])->json('data.id');

        $this->asApp($other->createToken('phone')->plainTextToken, $organization)
            ->postJson("/api/app/conversations/{$conversationId}/mute", ['muted' => true])
            ->assertOk()
            ->assertJsonPath('data.muted', true);

        $this->asApp($token, $organization)->postJson("/api/app/conversations/{$conversationId}/messages", [
            'body' => 'Hello muted',
        ])->assertCreated();

        $this->app->call([new SendChatPush((int) ChatMessage::query()->value('id')), 'handle']);

        Http::assertNothingSent();

        $this->asApp($other->createToken('phone-2')->plainTextToken, $organization)
            ->getJson('/api/app/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.muted', true)
            ->assertJsonPath('data.0.id', $conversationId);
    }

    public function test_archived_chats_leave_the_inbox_until_restored(): void
    {
        [, $organization, $token] = $this->member();
        $other = User::factory()->create(['name' => 'Ada']);
        $organization->users()->attach($other->id, ['role' => 'member']);

        $conversationId = $this->asApp($token, $organization)->postJson('/api/app/conversations', [
            'type' => 'direct',
            'user_ids' => [$other->id],
        ])->json('data.id');

        $this->asApp($token, $organization)->postJson("/api/app/conversations/{$conversationId}/archive", [
            'archived' => true,
        ])->assertOk()
            ->assertJsonPath('data.archived', true);

        $this->asApp($token, $organization)->getJson('/api/app/conversations')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->asApp($token, $organization)->getJson('/api/app/conversations?archived=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversationId)
            ->assertJsonPath('data.0.archived', true);

        $this->asApp($token, $organization)->postJson("/api/app/conversations/{$conversationId}/archive", [
            'archived' => false,
        ])->assertOk()
            ->assertJsonPath('data.archived', false);

        $this->asApp($token, $organization)->getJson('/api/app/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversationId);
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
