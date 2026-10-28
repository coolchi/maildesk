<?php

namespace Tests\Feature;

use App\Jobs\FetchInboundEmailBody;
use App\Jobs\PushInboundToStalwart;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Thread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FetchInboundEmailBodyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'maildesk.stalwart.enabled' => true,
            'services.resend.key' => 're_test_key',
        ]);
    }

    protected function createMailboxWithImap(array $overrides = []): Mailbox
    {
        return Mailbox::factory()->create(array_merge([
            'imap_enabled' => true,
            'imap_password' => 'test-password',
            'status' => 'active',
        ], $overrides));
    }

    protected function createResendMessage(Mailbox $mailbox, array $overrides = []): Message
    {
        $thread = Thread::factory()->create([
            'organization_id' => $mailbox->organization_id,
            'mailbox_id' => $mailbox->id,
        ]);

        return Message::factory()->create(array_merge([
            'organization_id' => $mailbox->organization_id,
            'mailbox_id' => $mailbox->id,
            'thread_id' => $thread->id,
            'direction' => 'inbound',
            'status' => 'received',
            'provider' => 'resend',
            'provider_message_id' => 're_123',
            'from_email' => 'sender@example.com',
            'to' => [$mailbox->email],
            'subject' => 'Test',
            'text_body' => null,
            'html_body' => null,
            'received_at' => now(),
            'stalwart_pushed_at' => null,
        ], $overrides));
    }

    public function test_dispatches_stalwart_push_when_content_already_exists(): void
    {
        Queue::fake([PushInboundToStalwart::class]);

        $mailbox = $this->createMailboxWithImap();
        $message = $this->createResendMessage($mailbox, [
            'text_body' => 'Already has content',
            'html_body' => '<p>Already has content</p>',
        ]);

        $job = new FetchInboundEmailBody($message->id, 're_123');
        $job->handle();

        Queue::assertPushed(PushInboundToStalwart::class, fn ($j) => $j->messageId === $message->id);
    }

    public function test_dispatches_stalwart_push_after_fetching_content(): void
    {
        Queue::fake([PushInboundToStalwart::class]);
        Http::fake([
            'api.resend.com/emails/receiving/re_123' => Http::response([
                'text' => 'Fetched body',
                'html' => '<p>Fetched body</p>',
            ]),
            'api.resend.com/emails/receiving/re_123/attachments' => Http::response(['data' => []]),
        ]);

        $mailbox = $this->createMailboxWithImap();
        $message = $this->createResendMessage($mailbox);

        $job = new FetchInboundEmailBody($message->id, 're_123');
        $job->handle();

        Queue::assertPushed(PushInboundToStalwart::class, fn ($j) => $j->messageId === $message->id);
    }

    public function test_stalwart_push_is_idempotent_via_pushed_at(): void
    {
        Queue::fake([PushInboundToStalwart::class]);

        $mailbox = $this->createMailboxWithImap();
        $message = $this->createResendMessage($mailbox, [
            'text_body' => 'Has content',
            'stalwart_pushed_at' => now(),
        ]);

        $job = new FetchInboundEmailBody($message->id, 're_123');
        $job->handle();

        Queue::assertNotPushed(PushInboundToStalwart::class);
    }

    public function test_does_not_dispatch_when_stalwart_disabled(): void
    {
        config(['maildesk.stalwart.enabled' => false]);
        Queue::fake([PushInboundToStalwart::class]);

        $mailbox = $this->createMailboxWithImap();
        $message = $this->createResendMessage($mailbox, [
            'text_body' => 'Has content',
        ]);

        $job = new FetchInboundEmailBody($message->id, 're_123');
        $job->handle();

        Queue::assertNotPushed(PushInboundToStalwart::class);
    }

    public function test_does_not_dispatch_when_mailbox_not_imap_enabled(): void
    {
        Queue::fake([PushInboundToStalwart::class]);

        $mailbox = $this->createMailboxWithImap(['imap_enabled' => false]);
        $message = $this->createResendMessage($mailbox, [
            'text_body' => 'Has content',
        ]);

        $job = new FetchInboundEmailBody($message->id, 're_123');
        $job->handle();

        Queue::assertNotPushed(PushInboundToStalwart::class);
    }
}
