<?php

namespace Tests\Feature;

use App\Jobs\PushInboundToStalwart;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Thread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PushToStalwartCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([PushInboundToStalwart::class]);
        config(['maildesk.stalwart.enabled' => true]);
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
            'text_body' => 'Test body',
            'received_at' => now(),
            'stalwart_pushed_at' => null,
        ], $overrides));
    }

    public function test_command_fails_when_stalwart_disabled(): void
    {
        config(['maildesk.stalwart.enabled' => false]);

        $this->artisan('mail:push-to-stalwart')
            ->assertExitCode(1)
            ->expectsOutput('Stalwart is not enabled. Set STALWART_ENABLED=true in your environment.');
    }

    public function test_command_warns_when_no_mailboxes_found(): void
    {
        $this->artisan('mail:push-to-stalwart')
            ->assertSuccessful()
            ->expectsOutput('No IMAP-enabled mailboxes found.');
    }

    public function test_command_dispatches_jobs_for_unpushed_messages(): void
    {
        $mailbox = $this->createMailboxWithImap();
        $message1 = $this->createInboundMessage($mailbox);
        $message2 = $this->createInboundMessage($mailbox);

        $this->artisan('mail:push-to-stalwart')
            ->assertSuccessful();

        Queue::assertPushed(PushInboundToStalwart::class, 2);
        Queue::assertPushed(PushInboundToStalwart::class, fn ($job) => $job->messageId === $message1->id);
        Queue::assertPushed(PushInboundToStalwart::class, fn ($job) => $job->messageId === $message2->id);
    }

    public function test_command_skips_already_pushed_messages(): void
    {
        $mailbox = $this->createMailboxWithImap();
        $unpushed = $this->createInboundMessage($mailbox);
        $pushed = $this->createInboundMessage($mailbox, ['stalwart_pushed_at' => now()]);

        $this->artisan('mail:push-to-stalwart')
            ->assertSuccessful();

        Queue::assertPushed(PushInboundToStalwart::class, 1);
        Queue::assertPushed(PushInboundToStalwart::class, fn ($job) => $job->messageId === $unpushed->id);
    }

    public function test_command_filters_by_mailbox(): void
    {
        $mailbox1 = $this->createMailboxWithImap(['email' => 'mailbox1@acme.test']);
        $mailbox2 = $this->createMailboxWithImap(['email' => 'mailbox2@acme.test']);

        $msg1 = $this->createInboundMessage($mailbox1);
        $msg2 = $this->createInboundMessage($mailbox2);

        $this->artisan('mail:push-to-stalwart', ['--mailbox' => 'mailbox1@acme.test'])
            ->assertSuccessful();

        Queue::assertPushed(PushInboundToStalwart::class, 1);
        Queue::assertPushed(PushInboundToStalwart::class, fn ($job) => $job->messageId === $msg1->id);
    }

    public function test_command_filters_by_since_date(): void
    {
        $mailbox = $this->createMailboxWithImap();

        $old = $this->createInboundMessage($mailbox, [
            'received_at' => now()->subDays(10),
        ]);
        $recent = $this->createInboundMessage($mailbox, [
            'received_at' => now()->subDays(2),
        ]);

        $this->artisan('mail:push-to-stalwart', ['--since' => '-7 days'])
            ->assertSuccessful();

        Queue::assertPushed(PushInboundToStalwart::class, 1);
        Queue::assertPushed(PushInboundToStalwart::class, fn ($job) => $job->messageId === $recent->id);
    }

    public function test_command_dry_run_does_not_dispatch(): void
    {
        $mailbox = $this->createMailboxWithImap();
        $this->createInboundMessage($mailbox);

        $this->artisan('mail:push-to-stalwart', ['--dry-run' => true])
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_command_skips_messages_without_body(): void
    {
        $mailbox = $this->createMailboxWithImap();
        $withBody = $this->createInboundMessage($mailbox);
        $noBody = $this->createInboundMessage($mailbox, [
            'text_body' => null,
            'html_body' => null,
        ]);

        $this->artisan('mail:push-to-stalwart')
            ->assertSuccessful();

        Queue::assertPushed(PushInboundToStalwart::class, 1);
        Queue::assertPushed(PushInboundToStalwart::class, fn ($job) => $job->messageId === $withBody->id);
    }

    public function test_backfill_is_idempotent(): void
    {
        $mailbox = $this->createMailboxWithImap();
        $message = $this->createInboundMessage($mailbox);

        $this->artisan('mail:push-to-stalwart')->assertSuccessful();
        Queue::assertPushed(PushInboundToStalwart::class, 1);

        $message->forceFill(['stalwart_pushed_at' => now()])->save();

        Queue::fake([PushInboundToStalwart::class]);

        $this->artisan('mail:push-to-stalwart')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_command_ignores_mailboxes_without_imap_password(): void
    {
        $validMailbox = $this->createMailboxWithImap();
        $noPassword = $this->createMailboxWithImap(['imap_password' => null]);

        $this->createInboundMessage($validMailbox);
        $this->createInboundMessage($noPassword);

        $this->artisan('mail:push-to-stalwart')->assertSuccessful();

        Queue::assertPushed(PushInboundToStalwart::class, 1);
    }
}
