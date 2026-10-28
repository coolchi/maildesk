<?php

namespace Tests\Feature;

use App\Jobs\PushOutboundToStalwart;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Thread;
use App\Services\Imap\FakeImapConnection;
use App\Services\Imap\ImapConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushOutboundToStalwartTest extends TestCase
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

    protected function createOutboundMessage(Mailbox $mailbox, array $overrides = []): Message
    {
        $thread = Thread::factory()->create([
            'organization_id' => $mailbox->organization_id,
            'mailbox_id' => $mailbox->id,
        ]);

        return Message::factory()->create(array_merge([
            'organization_id' => $mailbox->organization_id,
            'mailbox_id' => $mailbox->id,
            'thread_id' => $thread->id,
            'direction' => 'outbound',
            'status' => 'delivered',
            'from_email' => $mailbox->email,
            'from_name' => 'Test Sender',
            'to' => ['customer@example.com'],
            'subject' => 'Test outbound message',
            'text_body' => 'This is a test message body.',
            'html_body' => '<p>This is a test message body.</p>',
            'sent_at' => now(),
            'stalwart_pushed_at' => null,
        ], $overrides));
    }

    public function test_job_appends_message_to_sent_folder(): void
    {
        $mailbox = $this->createMailboxWithImap();
        $message = $this->createOutboundMessage($mailbox);

        $job = new PushOutboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $this->fakeImap->assertAppended('Sent');
        $this->assertTrue($this->fakeImap->openCalled);
        $this->assertTrue($this->fakeImap->closeCalled);

        $message->refresh();
        $this->assertNotNull($message->stalwart_pushed_at);
    }

    public function test_job_skips_cleanly_when_mailbox_has_no_imap_credentials(): void
    {
        $mailbox = $this->createMailboxWithImap(['imap_password' => null]);
        $message = $this->createOutboundMessage($mailbox);

        $job = new PushOutboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $this->fakeImap->assertNothingAppended();
        $this->assertFalse($this->fakeImap->openCalled);
        $this->assertNull($message->fresh()->stalwart_pushed_at);
    }

    public function test_job_skips_cleanly_when_mailbox_imap_disabled(): void
    {
        $mailbox = $this->createMailboxWithImap(['imap_enabled' => false]);
        $message = $this->createOutboundMessage($mailbox);

        $job = new PushOutboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $this->fakeImap->assertNothingAppended();
        $this->assertFalse($this->fakeImap->openCalled);
    }

    public function test_job_skips_cleanly_when_stalwart_disabled(): void
    {
        config(['maildesk.stalwart.enabled' => false]);

        $mailbox = $this->createMailboxWithImap();
        $message = $this->createOutboundMessage($mailbox);

        $job = new PushOutboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $this->fakeImap->assertNothingAppended();
    }

    public function test_job_finds_sent_folder_by_name(): void
    {
        $this->fakeImap->folders = [
            'INBOX' => ['name' => 'INBOX', 'attributes' => []],
            'Sent Items' => ['name' => 'Sent Items', 'attributes' => []],
        ];

        $mailbox = $this->createMailboxWithImap();
        $message = $this->createOutboundMessage($mailbox);

        $job = new PushOutboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $this->fakeImap->assertAppended('Sent Items');
    }

    public function test_job_creates_sent_folder_when_not_exists(): void
    {
        $this->fakeImap->clearFolders();

        $mailbox = $this->createMailboxWithImap();
        $message = $this->createOutboundMessage($mailbox);

        $job = new PushOutboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $this->assertContains('Sent', $this->fakeImap->createdFolders);
        $this->assertContains('Sent', $this->fakeImap->subscribedFolders);
        $this->fakeImap->assertAppended('Sent');
    }

    public function test_job_skips_cleanly_when_append_fails(): void
    {
        $this->fakeImap->shouldFailAppend = true;

        $mailbox = $this->createMailboxWithImap();
        $message = $this->createOutboundMessage($mailbox);

        $job = new PushOutboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $this->assertNull($message->fresh()->stalwart_pushed_at);
    }

    public function test_job_skips_cleanly_when_folder_creation_fails(): void
    {
        $this->fakeImap->clearFolders();
        $this->fakeImap->shouldFailCreateFolder = true;

        $mailbox = $this->createMailboxWithImap();
        $message = $this->createOutboundMessage($mailbox);

        $job = new PushOutboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $this->fakeImap->assertNothingAppended();
        $this->assertNull($message->fresh()->stalwart_pushed_at);
    }

    public function test_handles_corrupted_references_in_outbound(): void
    {
        $mailbox = $this->createMailboxWithImap();
        $message = $this->createOutboundMessage($mailbox, [
            'references' => ['<["<msg1@example.com>","<msg2@example.com>"]>'],
        ]);

        $job = new PushOutboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $this->fakeImap->assertAppended('Sent');
        $appended = $this->fakeImap->lastAppendedMessage();
        $this->assertStringContainsString('msg1@example.com', $appended);
        $this->assertStringContainsString('msg2@example.com', $appended);
    }

    public function test_includes_x_maildesk_header(): void
    {
        $mailbox = $this->createMailboxWithImap();
        $message = $this->createOutboundMessage($mailbox);

        $job = new PushOutboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $appended = $this->fakeImap->lastAppendedMessage();
        $this->assertStringContainsString('X-MailDesk-Message-Id: '.$message->uuid, $appended);
    }

    public function test_appends_with_seen_flag(): void
    {
        $mailbox = $this->createMailboxWithImap();
        $message = $this->createOutboundMessage($mailbox);

        $job = new PushOutboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $lastAppended = end($this->fakeImap->appendedMessages);
        $this->assertSame('\\Seen', $lastAppended['flags']);
    }

    public function test_skips_already_pushed_message(): void
    {
        $mailbox = $this->createMailboxWithImap();
        $message = $this->createOutboundMessage($mailbox, [
            'stalwart_pushed_at' => now(),
        ]);

        $job = new PushOutboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $this->fakeImap->assertNothingAppended();
    }

    public function test_skips_inbound_messages(): void
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
            'direction' => 'inbound',
            'status' => 'received',
        ]);

        $job = new PushOutboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $this->fakeImap->assertNothingAppended();
    }

    public function test_increments_push_attempts_on_append_failure(): void
    {
        $this->fakeImap->shouldFailAppend = true;

        $mailbox = $this->createMailboxWithImap();
        $message = $this->createOutboundMessage($mailbox, ['stalwart_push_attempts' => 0]);

        $this->assertSame(0, $message->stalwart_push_attempts);

        $job = new PushOutboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $message->refresh();
        $this->assertSame(1, $message->stalwart_push_attempts);
        $this->assertNull($message->stalwart_pushed_at);
    }

    public function test_finds_sent_items_folder_case_insensitively(): void
    {
        $this->fakeImap->folders = [
            'INBOX' => ['name' => 'INBOX', 'attributes' => []],
            'SENT ITEMS' => ['name' => 'SENT ITEMS', 'attributes' => []],
        ];

        $mailbox = $this->createMailboxWithImap();
        $message = $this->createOutboundMessage($mailbox);

        $job = new PushOutboundToStalwart($message->id);
        $job->handle($this->fakeImap);

        $this->fakeImap->assertAppended('SENT ITEMS');
    }
}
