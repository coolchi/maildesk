<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Services\Imap\FakeImapConnection;
use App\Services\Imap\ImapConnection;
use App\Services\Imap\ImapSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImapSyncPeekTest extends TestCase
{
    use RefreshDatabase;

    protected FakeImapConnection $fakeImap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImap = new FakeImapConnection;
        $this->app->instance(ImapConnection::class, $this->fakeImap);

        config(['maildesk.stalwart.enabled' => true]);
    }

    protected function createMailboxWithImap(array $overrides = []): Mailbox
    {
        return Mailbox::factory()->create(array_merge([
            'imap_enabled' => true,
            'imap_password' => 'test-password',
            'status' => 'active',
            'inbox' => true,
        ], $overrides));
    }

    protected function unreadRfc822(string $mailboxEmail, string $messageId = '<unread@example.com>'): string
    {
        return "From: External Sender <customer@example.com>\r\n".
            "To: {$mailboxEmail}\r\n".
            "Subject: Unread inbound\r\n".
            "Message-ID: {$messageId}\r\n".
            "\r\n".
            'Please keep this unread.';
    }

    public function test_peek_flags_include_ft_peek_and_ft_uid(): void
    {
        if (! defined('FT_UID') || ! defined('FT_PEEK')) {
            $this->markTestSkipped('PHP IMAP extension is required.');
        }

        $flags = ImapConnection::peekFlags();

        $this->assertSame(FT_UID | FT_PEEK, $flags);
        $this->assertGreaterThan(0, $flags & FT_PEEK);
        $this->assertGreaterThan(0, $flags & FT_UID);
    }

    public function test_sync_does_not_set_seen_when_fetching_unread_mail(): void
    {
        $mailbox = $this->createMailboxWithImap();
        $this->fakeImap->addInboxMessage(
            uid: 7,
            raw: $this->unreadRfc822($mailbox->email),
            seen: false,
            overview: [
                'from' => 'External Sender <customer@example.com>',
                'subject' => 'Unread inbound',
                'message_id' => '<unread@example.com>',
            ],
        );

        $synced = app(ImapSyncService::class)->syncMailbox($mailbox);

        $this->assertSame(1, $synced);
        $this->assertSame([7], $this->fakeImap->peekedUids);
        $this->assertFalse($this->fakeImap->isSeen(7));
        $this->assertDatabaseHas('messages', [
            'mailbox_id' => $mailbox->id,
            'message_id_header' => '<unread@example.com>',
        ]);
        $this->assertTrue($mailbox->fresh()->threads()->where('is_read', false)->exists());
    }

    public function test_sync_leaves_thread_unread_when_imap_flags_are_unseen(): void
    {
        $mailbox = $this->createMailboxWithImap();
        $this->fakeImap->addInboxMessage(
            uid: 3,
            raw: $this->unreadRfc822($mailbox->email, '<still-unread@example.com>'),
            seen: false,
            overview: [
                'from' => 'customer@example.com',
                'subject' => 'Unread inbound',
                'message_id' => '<still-unread@example.com>',
            ],
        );

        app(ImapSyncService::class)->syncMailbox($mailbox);

        $this->assertFalse($this->fakeImap->isSeen(3));
        $this->assertFalse($mailbox->fresh()->threads()->first()?->is_read);
    }

    public function test_sync_mirrors_seen_from_flags_without_marking_unseen_mail(): void
    {
        $mailbox = $this->createMailboxWithImap();
        $this->fakeImap->addInboxMessage(
            uid: 4,
            raw: $this->unreadRfc822($mailbox->email, '<already-read@example.com>'),
            seen: true,
            overview: [
                'from' => 'customer@example.com',
                'subject' => 'Already read in IMAP',
                'message_id' => '<already-read@example.com>',
            ],
        );

        app(ImapSyncService::class)->syncMailbox($mailbox);

        $this->assertTrue($this->fakeImap->isSeen(4));
        $this->assertTrue($mailbox->fresh()->threads()->first()?->is_read);
    }

    public function test_sync_peeks_mail_desk_pushed_messages_without_setting_seen(): void
    {
        $mailbox = $this->createMailboxWithImap();
        $raw = "From: MailDesk <{$mailbox->email}>\r\n".
            "To: customer@example.com\r\n".
            "Subject: Already in MailDesk\r\n".
            "X-MailDesk-Message-Id: 550e8400-e29b-41d4-a716-446655440000\r\n".
            "\r\n".
            'Pushed copy';

        $this->fakeImap->addInboxMessage(uid: 9, raw: $raw, seen: false);

        $synced = app(ImapSyncService::class)->syncMailbox($mailbox);

        $this->assertSame(0, $synced);
        $this->assertSame([9], $this->fakeImap->peekedUids);
        $this->assertFalse($this->fakeImap->isSeen(9));
        $this->assertDatabaseMissing('messages', [
            'mailbox_id' => $mailbox->id,
            'provider' => 'stalwart-imap',
        ]);
    }
}
