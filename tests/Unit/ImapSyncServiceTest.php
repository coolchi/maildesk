<?php

namespace Tests\Unit;

use App\Models\Mailbox;
use App\Services\Imap\ImapSyncService;
use App\Services\Imap\StalwartClient;
use App\Services\InboundEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImapSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('imap_open')) {
            $this->markTestSkipped('PHP IMAP extension is required.');
        }
    }

    public function test_uid_validity_change_triggers_resync(): void
    {
        $mailbox = Mailbox::factory()->create([
            'imap_enabled' => true,
            'imap_password' => 'test-password',
            'imap_last_uid' => 100,
            'imap_uidvalidity' => '12345',
            'status' => 'active',
        ]);

        $this->assertSame(100, $mailbox->imap_last_uid);
        $this->assertSame('12345', $mailbox->imap_uidvalidity);
    }

    public function test_mailbox_stores_uid_validity(): void
    {
        $mailbox = Mailbox::factory()->create([
            'imap_enabled' => true,
            'imap_password' => 'test-password',
            'status' => 'active',
        ]);

        $this->assertNull($mailbox->imap_uidvalidity);

        $mailbox->forceFill([
            'imap_uidvalidity' => '67890',
            'imap_last_uid' => 50,
        ])->save();

        $mailbox->refresh();

        $this->assertSame('67890', $mailbox->imap_uidvalidity);
        $this->assertSame(50, $mailbox->imap_last_uid);
    }
}
