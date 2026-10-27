<?php

namespace Tests\Feature;

use App\Jobs\PushInboundToStalwart;
use App\Mail\DTO\InboundEmail;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Thread;
use App\Services\Imap\FakeImapConnection;
use App\Services\Imap\ImapConnection;
use App\Services\InboundEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PushInboundToStalwartTest extends TestCase
{
    use RefreshDatabase;

    protected FakeImapConnection $fakeImap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImap = new FakeImapConnection;
        $this->app->instance(ImapConnection::class, $this->fakeImap);

        config([
            'maildesk.stalwart.enabled' => true,
            'maildesk.stalwart.clients.imap.host' => 'fake.stalwart.test',
            'maildesk.stalwart.clients.imap.port' => 993,
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

    protected function createInboundMessage(Mailbox $mailbox, array $overrides = []): Message
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
            'from_email' => 'customer@example.com',
            'from_name' => 'Test Customer',
            'to' => [$mailbox->email],
            'subject' => 'Test inbound message',
            'text_body' => 'This is a test message body.',
            'html_body' => '<p>This is a test message body.</p>',
            'received_at' => now(),
            'stalwart_pushed_at' => null,
        ], $overrides));
    }

    public function test_job_appends_message_to_stalwart(): void
    {
        $mailbox = $this->createMailboxWithImap();
        $message = $this->createInboundMessage($mailbox);

        $job = new PushInboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $this->fakeImap->assertAppended('INBOX');
        $this->assertTrue($this->fakeImap->openCalled);
        $this->assertTrue($this->fakeImap->closeCalled);
        $this->assertSame($mailbox->email, $this->fakeImap->lastUsername);
        $this->assertSame('test-password', $this->fakeImap->lastPassword);

        $message->refresh();
        $this->assertNotNull($message->stalwart_pushed_at);
    }

    public function test_job_includes_x_maildesk_message_id_header(): void
    {
        $mailbox = $this->createMailboxWithImap();
        $message = $this->createInboundMessage($mailbox);

        $job = new PushInboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $appended = $this->fakeImap->lastAppendedMessage();
        $this->assertStringContainsString('X-MailDesk-Message-Id: '.$message->uuid, $appended);
    }

    public function test_job_skips_when_stalwart_disabled(): void
    {
        config(['maildesk.stalwart.enabled' => false]);

        $mailbox = $this->createMailboxWithImap();
        $message = $this->createInboundMessage($mailbox);

        $job = new PushInboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $this->fakeImap->assertNothingAppended();
        $this->assertNull($message->fresh()->stalwart_pushed_at);
    }

    public function test_job_skips_when_mailbox_imap_disabled(): void
    {
        $mailbox = $this->createMailboxWithImap(['imap_enabled' => false]);
        $message = $this->createInboundMessage($mailbox);

        $job = new PushInboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $this->fakeImap->assertNothingAppended();
    }

    public function test_job_skips_when_mailbox_has_no_imap_password(): void
    {
        $mailbox = $this->createMailboxWithImap(['imap_password' => null]);
        $message = $this->createInboundMessage($mailbox);

        $job = new PushInboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $this->fakeImap->assertNothingAppended();
    }

    public function test_job_skips_already_pushed_message(): void
    {
        $mailbox = $this->createMailboxWithImap();
        $message = $this->createInboundMessage($mailbox, [
            'stalwart_pushed_at' => now(),
        ]);

        $job = new PushInboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $this->fakeImap->assertNothingAppended();
    }

    public function test_job_skips_outbound_message(): void
    {
        $mailbox = $this->createMailboxWithImap();
        $thread = Thread::factory()->create([
            'organization_id' => $mailbox->organization_id,
            'mailbox_id' => $mailbox->id,
        ]);
        $message = Message::factory()->create([
            'organization_id' => $mailbox->organization_id,
            'mailbox_id' => $mailbox->id,
            'thread_id' => $thread->id,
            'direction' => 'outbound',
            'status' => 'delivered',
        ]);

        $job = new PushInboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $this->fakeImap->assertNothingAppended();
    }

    public function test_job_throws_on_imap_failure(): void
    {
        $mailbox = $this->createMailboxWithImap();
        $message = $this->createInboundMessage($mailbox);

        $this->fakeImap->shouldFailOpen = true;

        $job = new PushInboundToStalwart($message->id);

        $this->expectException(\RuntimeException::class);
        $job->handle($this->fakeImap);
    }

    public function test_job_throws_on_append_failure(): void
    {
        $mailbox = $this->createMailboxWithImap();
        $message = $this->createInboundMessage($mailbox);

        $this->fakeImap->shouldFailAppend = true;

        $job = new PushInboundToStalwart($message->id);

        $this->expectException(\RuntimeException::class);
        $job->handle($this->fakeImap);
    }

    public function test_inbound_email_service_dispatches_job_when_enabled(): void
    {
        Queue::fake([PushInboundToStalwart::class]);

        $mailbox = $this->createMailboxWithImap();

        $email = new InboundEmail(
            fromEmail: 'customer@example.com',
            fromName: 'Customer',
            to: [$mailbox->email],
            subject: 'Test email',
            text: 'Test body',
            html: '<p>Test body</p>',
            messageId: '<test@example.com>',
            provider: 'generic',
        );

        $service = app(InboundEmailService::class);
        $message = $service->receive($email);

        $this->assertNotNull($message);
        Queue::assertPushed(PushInboundToStalwart::class, fn ($job) => $job->messageId === $message->id);
    }

    public function test_inbound_email_service_does_not_dispatch_when_stalwart_disabled(): void
    {
        config(['maildesk.stalwart.enabled' => false]);
        Queue::fake([PushInboundToStalwart::class]);

        $mailbox = $this->createMailboxWithImap();

        $email = new InboundEmail(
            fromEmail: 'customer@example.com',
            fromName: 'Customer',
            to: [$mailbox->email],
            subject: 'Test email',
            text: 'Test body',
            html: '<p>Test body</p>',
            messageId: '<test2@example.com>',
            provider: 'generic',
        );

        $service = app(InboundEmailService::class);
        $service->receive($email);

        Queue::assertNotPushed(PushInboundToStalwart::class);
    }

    public function test_inbound_email_service_does_not_dispatch_when_mailbox_not_imap_enabled(): void
    {
        Queue::fake([PushInboundToStalwart::class]);

        $mailbox = $this->createMailboxWithImap(['imap_enabled' => false]);

        $email = new InboundEmail(
            fromEmail: 'customer@example.com',
            fromName: 'Customer',
            to: [$mailbox->email],
            subject: 'Test email',
            text: 'Test body',
            html: '<p>Test body</p>',
            messageId: '<test3@example.com>',
            provider: 'generic',
        );

        $service = app(InboundEmailService::class);
        $service->receive($email);

        Queue::assertNotPushed(PushInboundToStalwart::class);
    }

    public function test_message_includes_all_email_fields(): void
    {
        $mailbox = $this->createMailboxWithImap();
        $message = $this->createInboundMessage($mailbox, [
            'message_id_header' => '<unique-123@example.com>',
            'in_reply_to' => '<parent@example.com>',
            'references' => ['<ref1@example.com>', '<ref2@example.com>'],
            'reply_to' => ['reply@example.com'],
        ]);

        $job = new PushInboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $appended = $this->fakeImap->lastAppendedMessage();

        $this->assertStringContainsString('Message-ID: <unique-123@example.com>', $appended);
        $this->assertStringContainsString('In-Reply-To: <parent@example.com>', $appended);
        $this->assertStringContainsString('References:', $appended);
    }
}
