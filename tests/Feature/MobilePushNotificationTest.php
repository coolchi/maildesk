<?php

namespace Tests\Feature;

use App\Jobs\SendMobilePushNotification;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\MobileDeviceToken;
use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use App\Services\WorkspaceAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MobilePushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private User $member;

    private Mailbox $mailbox;

    private Thread $thread;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();

        $this->org = Organization::factory()->create();
        $this->org->users()->attach($this->owner->id, ['role' => 'owner']);
        $this->org->users()->attach($this->member->id, ['role' => 'member']);

        $this->mailbox = Mailbox::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->member->id,
            'status' => 'active',
            'inbox' => true,
        ]);

        $this->thread = Thread::factory()->create([
            'organization_id' => $this->org->id,
            'mailbox_id' => $this->mailbox->id,
        ]);
    }

    public function test_device_registration_stores_token(): void
    {
        $token = $this->owner->createToken('Test')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/v1/mobile/devices', [
                'token' => 'ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]',
                'platform' => 'ios',
            ]);

        $response->assertOk()
            ->assertJson(['message' => 'Device registered.']);

        $this->assertDatabaseHas('mobile_device_tokens', [
            'user_id' => $this->owner->id,
            'token' => 'ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]',
            'platform' => 'ios',
        ]);
    }

    public function test_device_registration_updates_existing_token(): void
    {
        MobileDeviceToken::factory()->create([
            'user_id' => $this->member->id,
            'token' => 'ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]',
            'platform' => 'android',
        ]);

        $token = $this->owner->createToken('Test')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/v1/mobile/devices', [
                'token' => 'ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]',
                'platform' => 'ios',
            ]);

        $response->assertOk();

        $this->assertDatabaseCount('mobile_device_tokens', 1);
        $this->assertDatabaseHas('mobile_device_tokens', [
            'user_id' => $this->owner->id,
            'token' => 'ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]',
            'platform' => 'ios',
        ]);
    }

    public function test_device_unregistration_removes_token(): void
    {
        MobileDeviceToken::factory()->create([
            'user_id' => $this->owner->id,
            'token' => 'ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]',
        ]);

        $token = $this->owner->createToken('Test')->plainTextToken;

        $response = $this->withToken($token)
            ->deleteJson('/api/v1/mobile/devices', [
                'token' => 'ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]',
            ]);

        $response->assertOk()
            ->assertJson(['message' => 'Device unregistered.']);

        $this->assertDatabaseMissing('mobile_device_tokens', [
            'token' => 'ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]',
        ]);
    }

    public function test_device_unregistration_only_removes_own_token(): void
    {
        MobileDeviceToken::factory()->create([
            'user_id' => $this->member->id,
            'token' => 'ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]',
        ]);

        $token = $this->owner->createToken('Test')->plainTextToken;

        $this->withToken($token)
            ->deleteJson('/api/v1/mobile/devices', [
                'token' => 'ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]',
            ]);

        $this->assertDatabaseHas('mobile_device_tokens', [
            'user_id' => $this->member->id,
            'token' => 'ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]',
        ]);
    }

    public function test_push_notification_sent_on_inbound_message(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response([
                'data' => [['status' => 'ok']],
            ]),
        ]);

        MobileDeviceToken::factory()->create([
            'user_id' => $this->owner->id,
            'token' => 'ExponentPushToken[ownertoken]',
        ]);

        $message = Message::factory()->create([
            'organization_id' => $this->org->id,
            'thread_id' => $this->thread->id,
            'mailbox_id' => $this->mailbox->id,
            'direction' => 'inbound',
            'from_email' => 'customer@example.com',
            'from_name' => 'John Customer',
            'subject' => 'Help needed',
        ]);

        $job = new SendMobilePushNotification($message->id);
        $job->handle(app(WorkspaceAccess::class));

        Http::assertSent(function ($request) use ($message) {
            $body = $request->data();

            return $request->url() === 'https://exp.host/--/api/v2/push/send'
                && isset($body[0]['to'])
                && $body[0]['to'] === 'ExponentPushToken[ownertoken]'
                && $body[0]['title'] === 'John Customer'
                && $body[0]['body'] === 'Help needed'
                && $body[0]['data']['thread_id'] === $message->thread_id;
        });
    }

    public function test_push_notification_respects_mailbox_access(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response([
                'data' => [['status' => 'ok']],
            ]),
        ]);

        MobileDeviceToken::factory()->create([
            'user_id' => $this->owner->id,
            'token' => 'ExponentPushToken[ownertoken]',
        ]);

        MobileDeviceToken::factory()->create([
            'user_id' => $this->member->id,
            'token' => 'ExponentPushToken[membertoken]',
        ]);

        $message = Message::factory()->create([
            'organization_id' => $this->org->id,
            'thread_id' => $this->thread->id,
            'mailbox_id' => $this->mailbox->id,
            'direction' => 'inbound',
            'from_email' => 'customer@example.com',
            'subject' => 'Test',
        ]);

        $job = new SendMobilePushNotification($message->id);
        $job->handle(app(WorkspaceAccess::class));

        Http::assertSent(function ($request) {
            $body = $request->data();
            $tokens = collect($body)->pluck('to')->all();

            return in_array('ExponentPushToken[ownertoken]', $tokens, true)
                && in_array('ExponentPushToken[membertoken]', $tokens, true);
        });
    }

    public function test_push_notification_excludes_member_without_mailbox_access(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response([
                'data' => [['status' => 'ok']],
            ]),
        ]);

        $otherMailbox = Mailbox::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => null,
            'status' => 'active',
            'inbox' => true,
        ]);

        $otherThread = Thread::factory()->create([
            'organization_id' => $this->org->id,
            'mailbox_id' => $otherMailbox->id,
        ]);

        MobileDeviceToken::factory()->create([
            'user_id' => $this->owner->id,
            'token' => 'ExponentPushToken[ownertoken]',
        ]);

        MobileDeviceToken::factory()->create([
            'user_id' => $this->member->id,
            'token' => 'ExponentPushToken[membertoken]',
        ]);

        $message = Message::factory()->create([
            'organization_id' => $this->org->id,
            'thread_id' => $otherThread->id,
            'mailbox_id' => $otherMailbox->id,
            'direction' => 'inbound',
            'from_email' => 'customer@example.com',
            'subject' => 'Test',
        ]);

        $job = new SendMobilePushNotification($message->id);
        $job->handle(app(WorkspaceAccess::class));

        Http::assertSent(function ($request) {
            $body = $request->data();
            $tokens = collect($body)->pluck('to')->all();

            return in_array('ExponentPushToken[ownertoken]', $tokens, true)
                && ! in_array('ExponentPushToken[membertoken]', $tokens, true);
        });
    }

    public function test_push_notification_removes_expired_tokens(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response([
                'data' => [
                    [
                        'status' => 'error',
                        'message' => 'The device cannot receive push notifications anymore.',
                        'details' => [
                            'error' => 'DeviceNotRegistered',
                            'expoPushToken' => 'ExponentPushToken[expiredtoken]',
                        ],
                    ],
                ],
            ]),
        ]);

        MobileDeviceToken::factory()->create([
            'user_id' => $this->owner->id,
            'token' => 'ExponentPushToken[expiredtoken]',
        ]);

        $message = Message::factory()->create([
            'organization_id' => $this->org->id,
            'thread_id' => $this->thread->id,
            'mailbox_id' => $this->mailbox->id,
            'direction' => 'inbound',
        ]);

        $job = new SendMobilePushNotification($message->id);
        $job->handle(app(WorkspaceAccess::class));

        $this->assertDatabaseMissing('mobile_device_tokens', [
            'token' => 'ExponentPushToken[expiredtoken]',
        ]);
    }

    public function test_push_notification_disabled_by_config(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        config(['maildesk.mobile.push_enabled' => false]);

        MobileDeviceToken::factory()->create([
            'user_id' => $this->owner->id,
            'token' => 'ExponentPushToken[ownertoken]',
        ]);

        $message = Message::factory()->create([
            'organization_id' => $this->org->id,
            'thread_id' => $this->thread->id,
            'mailbox_id' => $this->mailbox->id,
            'direction' => 'inbound',
        ]);

        $job = new SendMobilePushNotification($message->id);
        $job->handle(app(WorkspaceAccess::class));

        Http::assertNothingSent();
    }

    public function test_push_notification_skips_outbound_messages(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        MobileDeviceToken::factory()->create([
            'user_id' => $this->owner->id,
            'token' => 'ExponentPushToken[ownertoken]',
        ]);

        $message = Message::factory()->create([
            'organization_id' => $this->org->id,
            'thread_id' => $this->thread->id,
            'mailbox_id' => $this->mailbox->id,
            'direction' => 'outbound',
        ]);

        $job = new SendMobilePushNotification($message->id);
        $job->handle(app(WorkspaceAccess::class));

        Http::assertNothingSent();
    }

    public function test_push_notification_shows_email_when_no_name(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response([
                'data' => [['status' => 'ok']],
            ]),
        ]);

        MobileDeviceToken::factory()->create([
            'user_id' => $this->owner->id,
            'token' => 'ExponentPushToken[ownertoken]',
        ]);

        $message = Message::factory()->create([
            'organization_id' => $this->org->id,
            'thread_id' => $this->thread->id,
            'mailbox_id' => $this->mailbox->id,
            'direction' => 'inbound',
            'from_email' => 'sender@example.com',
            'from_name' => null,
            'subject' => 'Test Subject',
        ]);

        $job = new SendMobilePushNotification($message->id);
        $job->handle(app(WorkspaceAccess::class));

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body[0]['title'] === 'sender@example.com';
        });
    }

    public function test_push_notification_job_dispatched_on_inbound_email(): void
    {
        Queue::fake([SendMobilePushNotification::class]);

        $message = Message::factory()->create([
            'organization_id' => $this->org->id,
            'thread_id' => $this->thread->id,
            'mailbox_id' => $this->mailbox->id,
            'direction' => 'inbound',
        ]);

        SendMobilePushNotification::dispatch($message->id);

        Queue::assertPushed(SendMobilePushNotification::class, function ($job) use ($message) {
            return $job->messageId === $message->id;
        });
    }

    public function test_device_registration_requires_authentication(): void
    {
        $this->postJson('/api/v1/mobile/devices', [
            'token' => 'ExponentPushToken[test]',
            'platform' => 'ios',
        ])->assertStatus(401);
    }

    public function test_device_registration_validates_platform(): void
    {
        $token = $this->owner->createToken('Test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/mobile/devices', [
                'token' => 'ExponentPushToken[test]',
                'platform' => 'windows',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('platform');
    }
}
