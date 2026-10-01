<?php

namespace Tests\Feature;

use App\Events\ChatDelivered;
use App\Events\ChatMessageSent;
use App\Events\ChatRead;
use App\Jobs\SendChatPush;
use App\Models\ChatMessage;
use App\Models\DeviceToken;
use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use App\Services\AccountAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MobileAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_rejects_a_wrong_password(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/app/login', [
            'email' => 'ada@example.com',
            'password' => 'nope',
        ])->assertUnprocessable()
            ->assertInvalid(['email' => 'These credentials do not match our records.']);
    }

    public function test_login_returns_a_token_and_workspaces(): void
    {
        [$user, $organization] = $this->member();

        $this->postJson('/api/app/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Pixel',
        ])->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('organizations.0.id', $organization->id)
            ->assertJsonStructure(['token', 'realtime' => ['auth_endpoint', 'host', 'port', 'scheme']]);
    }

    public function test_chat_requires_a_token(): void
    {
        $this->getJson('/api/app/conversations')->assertUnauthorized();
    }

    public function test_direct_chat_is_reused_and_messages_are_unread_for_the_other_person(): void
    {
        [$user, $organization, $token] = $this->member();
        $other = User::factory()->create();
        $organization->users()->attach($other->id, ['role' => 'member']);

        Event::fake([ChatMessageSent::class]);

        $created = $this->asApp($token, $organization)->postJson('/api/app/conversations', [
            'type' => 'direct',
            'user_ids' => [$other->id],
        ])->assertCreated()
            ->assertJsonPath('data.type', 'direct')
            ->assertJsonPath('data.name', $other->name);

        $conversationId = $created->json('data.id');

        $this->asApp($token, $organization)->postJson('/api/app/conversations', [
            'type' => 'direct',
            'user_ids' => [$other->id],
        ])->assertOk()
            ->assertJsonPath('data.id', $conversationId);

        $this->asApp($token, $organization)->postJson("/api/app/conversations/{$conversationId}/messages", [
            'body' => 'On my way',
        ])->assertCreated()
            ->assertJsonPath('data.body', 'On my way');

        Event::assertDispatched(ChatMessageSent::class);

        $this->asApp($token, $organization)->getJson('/api/app/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 0)
            ->assertJsonPath('data.0.preview', 'On my way');

        $otherToken = $other->createToken('phone')->plainTextToken;

        $this->asApp($otherToken, $organization)->getJson('/api/app/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 1)
            ->assertJsonPath('data.0.name', $user->name);
    }

    public function test_a_chat_message_is_sent_then_received_then_read(): void
    {
        [, $organization, $token] = $this->member();
        $other = User::factory()->create();
        $coworker = User::factory()->create();
        $organization->users()->attach($other->id, ['role' => 'member']);
        $organization->users()->attach($coworker->id, ['role' => 'member']);
        $otherToken = $other->createToken('phone')->plainTextToken;

        Event::fake([ChatDelivered::class, ChatRead::class]);

        $conversationId = $this->asApp($token, $organization)->postJson('/api/app/conversations', [
            'type' => 'direct',
            'user_ids' => [$other->id],
        ])->json('data.id');

        $this->asApp($token, $organization)->postJson("/api/app/conversations/{$conversationId}/messages", [
            'body' => 'On my way',
        ])->assertCreated();

        $before = collect($this->asApp($token, $organization)->getJson('/api/app/conversations')->json('data.0.participants'))
            ->firstWhere('id', $other->id);
        $this->assertNull($before['last_delivered_at']);
        $this->assertNull($before['last_read_at']);

        $this->asApp($coworker->createToken('phone')->plainTextToken, $organization)
            ->postJson("/api/app/conversations/{$conversationId}/delivered")
            ->assertForbidden();

        $this->asApp($otherToken, $organization)
            ->postJson("/api/app/conversations/{$conversationId}/delivered")
            ->assertOk();

        Event::assertDispatched(ChatDelivered::class);

        $received = collect($this->asApp($token, $organization)->getJson('/api/app/conversations')->json('data.0.participants'))
            ->firstWhere('id', $other->id);
        $this->assertNotNull($received['last_delivered_at']);
        $this->assertNull($received['last_read_at']);

        $this->asApp($otherToken, $organization)
            ->postJson("/api/app/conversations/{$conversationId}/delivered")
            ->assertOk();
        Event::assertDispatchedTimes(ChatDelivered::class, 1);

        $this->asApp($otherToken, $organization)
            ->postJson("/api/app/conversations/{$conversationId}/read")
            ->assertOk();

        Event::assertDispatched(ChatRead::class);

        $read = collect($this->asApp($token, $organization)->getJson('/api/app/conversations')->json('data.0.participants'))
            ->firstWhere('id', $other->id);
        $this->assertNotNull($read['last_read_at']);
        $this->assertNotNull($read['last_delivered_at']);
    }

    public function test_direct_chat_rejects_someone_outside_the_workspace(): void
    {
        [, $organization, $token] = $this->member();
        $outsider = User::factory()->create();

        $this->asApp($token, $organization)->postJson('/api/app/conversations', [
            'type' => 'direct',
            'user_ids' => [$outsider->id],
        ])->assertUnprocessable()
            ->assertInvalid(['user_ids' => 'One or more people are not in this workspace.']);

        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_group_requires_a_name_and_only_an_admin_can_add_people(): void
    {
        [$owner, $organization, $token] = $this->member();
        $member = User::factory()->create();
        $extra = User::factory()->create();
        $organization->users()->attach($member->id, ['role' => 'member']);
        $organization->users()->attach($extra->id, ['role' => 'member']);

        $this->asApp($token, $organization)->postJson('/api/app/conversations', [
            'type' => 'group',
            'user_ids' => [$member->id],
        ])->assertUnprocessable()
            ->assertInvalid(['name' => 'Give the group a name.']);

        $created = $this->asApp($token, $organization)->postJson('/api/app/conversations', [
            'type' => 'group',
            'name' => 'Launch',
            'user_ids' => [$member->id],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Launch');

        $conversationId = $created->json('data.id');
        $memberToken = $member->createToken('phone')->plainTextToken;

        $this->asApp($memberToken, $organization)->postJson("/api/app/conversations/{$conversationId}/members", [
            'user_ids' => [$extra->id],
        ])->assertForbidden();

        $this->asApp($token, $organization)->postJson("/api/app/conversations/{$conversationId}/members", [
            'user_ids' => [$extra->id],
        ])->assertOk();

        $extraToken = $extra->createToken('phone')->plainTextToken;

        $this->asApp($token, $organization)->postJson("/api/app/conversations/{$conversationId}/messages", [
            'body' => 'Welcome',
        ])->assertCreated();

        $this->asApp($extraToken, $organization)->getJson("/api/app/conversations/{$conversationId}")
            ->assertOk()
            ->assertJsonPath('messages.0.body', 'Welcome');
    }

    public function test_a_coworker_who_is_not_in_the_chat_cannot_read_it(): void
    {
        [, $organization, $token] = $this->member();
        $other = User::factory()->create();
        $coworker = User::factory()->create();
        $organization->users()->attach($other->id, ['role' => 'member']);
        $organization->users()->attach($coworker->id, ['role' => 'member']);

        $conversationId = $this->asApp($token, $organization)->postJson('/api/app/conversations', [
            'type' => 'direct',
            'user_ids' => [$other->id],
        ])->json('data.id');

        $coworkerToken = $coworker->createToken('phone')->plainTextToken;

        $this->asApp($coworkerToken, $organization)
            ->getJson("/api/app/conversations/{$conversationId}")
            ->assertForbidden();
    }

    public function test_another_workspace_cannot_see_the_conversation(): void
    {
        [, $organization, $token] = $this->member();
        $other = User::factory()->create();
        $organization->users()->attach($other->id, ['role' => 'member']);

        $conversationId = $this->asApp($token, $organization)->postJson('/api/app/conversations', [
            'type' => 'direct',
            'user_ids' => [$other->id],
        ])->json('data.id');

        [, $otherOrg, $strangerToken] = $this->member();

        $this->asApp($strangerToken, $otherOrg)
            ->getJson("/api/app/conversations/{$conversationId}")
            ->assertNotFound();
    }

    public function test_blank_message_is_rejected(): void
    {
        [, $organization, $token] = $this->member();
        $other = User::factory()->create();
        $organization->users()->attach($other->id, ['role' => 'member']);

        $conversationId = $this->asApp($token, $organization)->postJson('/api/app/conversations', [
            'type' => 'direct',
            'user_ids' => [$other->id],
        ])->json('data.id');

        $this->asApp($token, $organization)->postJson("/api/app/conversations/{$conversationId}/messages", [
            'body' => '',
        ])->assertUnprocessable()
            ->assertInvalid(['body' => 'Write a message.']);
    }

    public function test_suspended_workspace_cannot_open_chat(): void
    {
        [, $organization, $token] = $this->member();
        $organization->forceFill(['status' => 'suspended'])->save();

        $this->asApp($token, $organization)->getJson('/api/app/conversations')
            ->assertForbidden()
            ->assertJsonPath('message', AccountAccess::SUSPENDED_MESSAGE);
    }

    public function test_device_token_is_stored_for_the_signed_in_user(): void
    {
        [$user, , $token] = $this->member();

        $this->withToken($token)->postJson('/api/app/devices', [
            'token' => 'fcm-token-1',
            'platform' => 'android',
        ])->assertOk();

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $user->id,
            'token' => 'fcm-token-1',
            'platform' => 'android',
        ]);
    }

    public function test_push_is_sent_to_the_other_persons_device(): void
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

        $this->asApp($token, $organization)->postJson("/api/app/conversations/{$conversationId}/messages", [
            'body' => 'Ping',
        ])->assertCreated();

        $messageId = ChatMessage::query()->value('id');

        (new SendChatPush($messageId))->handle();

        Http::assertSent(function ($request) use ($user) {
            return $request->url() === 'https://fcm.googleapis.com/fcm/send'
                && $request['registration_ids'] === ['grace-phone']
                && $request['notification']['title'] === $user->name
                && $request['notification']['body'] === 'Ping';
        });
    }

    public function test_inbox_lists_a_thread_and_marks_it_read(): void
    {
        [, $organization, $token] = $this->member();
        $thread = Thread::factory()->create([
            'organization_id' => $organization->id,
            'subject' => 'Invoice 14',
            'snippet' => 'Please find the invoice attached.',
            'is_read' => false,
            'is_archived' => false,
            'is_spam' => false,
            'is_trashed' => false,
        ]);

        $this->asApp($token, $organization)->getJson('/api/app/inbox/threads')
            ->assertOk()
            ->assertJsonPath('data.0.subject', 'Invoice 14')
            ->assertJsonPath('data.0.unread', true);

        $this->asApp($token, $organization)->getJson("/api/app/inbox/threads/{$thread->id}")
            ->assertOk()
            ->assertJsonPath('data.subject', 'Invoice 14');

        $this->assertTrue($thread->fresh()->is_read);
    }

    public function test_a_missing_inbox_thread_says_the_conversation_is_gone(): void
    {
        [, $organization, $token] = $this->member();

        $this->asApp($token, $organization)->getJson('/api/app/inbox/threads/34')
            ->assertNotFound()
            ->assertJsonPath('message', 'This conversation is no longer available.');
    }

    public function test_the_author_can_delete_a_chat_message_and_someone_else_cannot(): void
    {
        [$user, $organization, $token] = $this->member();
        $other = User::factory()->create();
        $organization->users()->attach($other->id, ['role' => 'member']);
        $otherToken = $other->createToken('phone')->plainTextToken;

        $conversationId = $this->asApp($token, $organization)->postJson('/api/app/conversations', [
            'type' => 'direct',
            'user_ids' => [$other->id],
        ])->json('data.id');

        $this->asApp($token, $organization)->postJson("/api/app/conversations/{$conversationId}/messages", [
            'body' => 'Keep this',
        ])->assertCreated();

        $sent = $this->asApp($token, $organization)->postJson("/api/app/conversations/{$conversationId}/messages", [
            'body' => 'Remove this',
        ])->assertCreated();

        $messageId = $sent->json('data.id');

        $this->asApp($otherToken, $organization)
            ->deleteJson("/api/app/conversations/{$conversationId}/messages/{$messageId}")
            ->assertForbidden();

        $this->asApp($token, $organization)
            ->deleteJson("/api/app/conversations/{$conversationId}/messages/{$messageId}")
            ->assertOk()
            ->assertJsonPath('message', 'Message deleted.');

        $this->assertDatabaseMissing('chat_messages', ['id' => $messageId]);
        $this->asApp($token, $organization)->getJson('/api/app/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.preview', 'Keep this');
    }

    public function test_a_thread_can_be_marked_unread_and_read_again(): void
    {
        [, $organization, $token] = $this->member();
        $thread = Thread::factory()->create([
            'organization_id' => $organization->id,
            'subject' => 'Invoice 14',
            'is_read' => true,
            'is_archived' => false,
            'is_spam' => false,
            'is_trashed' => false,
        ]);

        $this->asApp($token, $organization)->postJson("/api/app/inbox/threads/{$thread->id}/unread")
            ->assertOk()
            ->assertJsonPath('message', 'Marked as unread.')
            ->assertJsonPath('data.unread', true);

        $this->assertFalse($thread->fresh()->is_read);

        $this->asApp($token, $organization)->postJson("/api/app/inbox/threads/{$thread->id}/read")
            ->assertOk()
            ->assertJsonPath('message', 'Marked as read.')
            ->assertJsonPath('data.unread', false);

        $this->assertTrue($thread->fresh()->is_read);
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
