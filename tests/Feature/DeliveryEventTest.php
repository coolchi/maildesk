<?php

namespace Tests\Feature;

use App\Jobs\DispatchWebhook;
use App\Models\Domain;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeliveryEventTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_'.'dGVzdC1zZWNyZXQtZm9yLW1haWxkZXNr';

    private Organization $org;

    private Message $message;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['maildesk.inbound.resend_webhook_secret' => self::SECRET]);

        $this->org = Organization::factory()->create();
        $this->message = Message::factory()->create([
            'organization_id' => $this->org->id,
            'direction' => 'outbound',
            'status' => 'sent',
            'provider' => 'resend',
            'provider_message_id' => 're_email_1',
            'to' => ['Customer@Example.com'],
            'meta' => null,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function event(string $type, array $data = [], string $url = '/api/v1/events/resend', ?string $id = null, ?string $secret = null)
    {
        $body = json_encode([
            'type' => $type,
            'created_at' => '2026-09-25T22:30:00.000Z',
            'data' => array_merge([
                'email_id' => 're_email_1',
                'to' => ['customer@example.com'],
                'subject' => 'Your order',
            ], $data),
        ]);
        $id ??= 'msg_'.uniqid();
        $timestamp = time();
        $key = base64_decode(substr($secret ?? self::SECRET, 6));
        $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

        return $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_SVIX_ID' => $id,
            'HTTP_SVIX_TIMESTAMP' => (string) $timestamp,
            'HTTP_SVIX_SIGNATURE' => "v1,{$signature}",
        ], $body);
    }

    public function test_delivered_event_updates_status_and_stores_details(): void
    {
        $this->event('email.delivered', id: 'evt_1')
            ->assertOk()
            ->assertJsonPath('status', 'processed')
            ->assertJsonPath('message_status', 'delivered');

        $this->message->refresh();
        $this->assertSame('delivered', $this->message->status);
        $this->assertSame('2026-09-25T22:30:00+00:00', $this->message->meta['delivered_at']);
        $this->assertSame('evt_1', $this->message->meta['events'][0]['id']);
        $this->assertSame('delivered', $this->message->meta['events'][0]['type']);
        $this->assertSame(0, Suppression::query()->count());
        Queue::assertPushed(DispatchWebhook::class, fn ($job) => $job->event === 'email.delivered');
    }

    public function test_hard_bounce_updates_status_and_suppresses_recipient(): void
    {
        $this->event('email.bounced', [
            'bounce' => ['type' => 'Permanent', 'subType' => 'General', 'message' => 'Mailbox does not exist'],
        ])->assertOk()->assertJsonPath('suppressed', ['customer@example.com']);

        $this->message->refresh();
        $this->assertSame('bounced', $this->message->status);
        $this->assertSame('Permanent', $this->message->meta['bounce']['type']);
        $this->assertSame('Mailbox does not exist', $this->message->meta['bounce']['reason']);
        $this->assertDatabaseHas('suppressions', [
            'organization_id' => $this->org->id,
            'email' => 'customer@example.com',
            'source' => 'bounce',
            'reason' => 'Hard bounce: Mailbox does not exist',
        ]);
    }

    public function test_bounced_address_is_blocked_on_the_next_send(): void
    {
        $this->event('email.bounced', ['bounce' => ['type' => 'Permanent', 'message' => 'No such user']]);

        $user = User::factory()->create();
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);
        $this->org->update(['mail_provider_id' => $provider->id, 'default_provider' => 'resend']);
        $this->org->users()->attach($user->id, ['role' => 'owner']);
        Domain::factory()->verified()->create(['organization_id' => $this->org->id, 'name' => 'acme.test']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->post(route('emails.store'), [
                'from' => 'hello@acme.test',
                'to' => 'CUSTOMER@example.com',
                'subject' => 'Follow up',
                'html' => '<p>Hi</p>',
            ])
            ->assertSessionHas('error');

        $this->assertSame('suppressed', Message::query()->latest('id')->first()->status);
    }

    public function test_soft_bounce_is_recorded_but_does_not_suppress(): void
    {
        $this->event('email.bounced', ['bounce' => ['type' => 'Transient', 'message' => 'Mailbox full']])->assertOk();

        $this->message->refresh();
        $this->assertSame('sent', $this->message->status);
        $this->assertSame('Transient', $this->message->meta['bounce']['type']);
        $this->assertSame(0, Suppression::query()->count());
    }

    public function test_complaint_updates_status_and_suppresses_recipient(): void
    {
        $this->event('email.complained')->assertOk()->assertJsonPath('message_status', 'complained');

        $this->message->refresh();
        $this->assertSame('complained', $this->message->status);
        $this->assertNotNull($this->message->meta['complained_at']);
        $this->assertDatabaseHas('suppressions', [
            'organization_id' => $this->org->id,
            'email' => 'customer@example.com',
            'source' => 'complaint',
            'reason' => 'Spam complaint',
        ]);
    }

    public function test_opened_event_records_the_open_without_changing_status(): void
    {
        $this->event('email.delivered');
        $this->event('email.opened', ['open' => ['ipAddress' => '203.0.113.9', 'userAgent' => 'Mail/1.0']])->assertOk();
        $this->event('email.opened')->assertOk();

        $this->message->refresh();
        $this->assertSame('delivered', $this->message->status);
        $this->assertSame(2, $this->message->meta['open_count']);
        $this->assertNotNull($this->message->meta['first_opened_at']);
        $this->assertSame('203.0.113.9', $this->message->meta['events'][1]['ip']);
        $this->assertSame(2, $this->message->toWorkspaceArray()['open_count']);
    }

    public function test_late_delivered_event_does_not_overwrite_a_bounce(): void
    {
        $this->event('email.bounced', ['bounce' => ['type' => 'Permanent']]);
        $this->event('email.delivered')->assertOk();

        $this->assertSame('bounced', $this->message->refresh()->status);
        $this->assertCount(2, $this->message->meta['events']);
    }

    public function test_duplicate_event_is_applied_once(): void
    {
        $this->event('email.opened', id: 'evt_same')->assertJsonPath('status', 'processed');
        $this->event('email.opened', id: 'evt_same')->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, $this->message->refresh()->meta['open_count']);
    }

    public function test_clicked_event_counts_clicks_and_dispatches_email_clicked(): void
    {
        $this->event('email.clicked', ['click' => ['link' => 'https://acme.test/offer', 'ipAddress' => '203.0.113.9']])
            ->assertOk()->assertJsonPath('status', 'processed');

        $meta = $this->message->refresh()->meta;
        $this->assertSame(1, $meta['click_count']);
        $this->assertSame('https://acme.test/offer', collect($meta['events'])->last()['link']);
        $this->assertSame('sent', $this->message->status);
        Queue::assertPushed(DispatchWebhook::class, fn (DispatchWebhook $job) => $job->event === 'email.clicked');
    }

    public function test_unknown_or_unhandled_events_are_ignored_safely(): void
    {
        $before = $this->message->fresh()->toArray();

        $this->event('email.delivery_delayed')->assertOk()->assertJsonPath('status', 'ignored');
        $this->event('contact.created')->assertOk()->assertJsonPath('status', 'ignored');
        $this->event('email.delivered', ['email_id' => 're_not_ours'])->assertOk()->assertJsonPath('status', 'ignored');
        $this->event('email.delivered', ['email_id' => null])->assertOk()->assertJsonPath('status', 'ignored');

        $this->assertSame($before['status'], $this->message->fresh()->status);
        $this->assertNull($this->message->fresh()->meta);
        Queue::assertNotPushed(DispatchWebhook::class);
    }

    public function test_events_for_inbound_messages_are_ignored(): void
    {
        $this->message->update(['direction' => 'inbound', 'status' => 'received']);

        $this->event('email.bounced', ['bounce' => ['type' => 'Permanent']])->assertJsonPath('status', 'ignored');
        $this->assertSame(0, Suppression::query()->count());
    }

    public function test_bad_signature_is_rejected(): void
    {
        $this->event('email.bounced', secret: 'whsec_'.base64_encode('wrong-secret'))->assertStatus(401);

        $this->assertSame('sent', $this->message->refresh()->status);
        $this->assertSame(0, Suppression::query()->count());
    }

    public function test_events_posted_to_the_inbound_url_are_handled_too(): void
    {
        $this->event('email.delivered', url: '/api/v1/inbound/resend')
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $this->assertSame('delivered', $this->message->refresh()->status);
    }

    public function test_unknown_event_driver_returns_404(): void
    {
        $this->postJson('/api/v1/events/postmark', [])->assertNotFound();
    }
}
