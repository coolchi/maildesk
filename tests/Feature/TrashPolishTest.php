<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TrashPolishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');
        config(['maildesk.inbound.generic_secret' => 'generic-secret']);
    }

    /**
     * @return array{0: User, 1: Organization, 2: Mailbox}
     */
    private function mailboxWorkspace(): array
    {
        $user = User::factory()->create(['email' => 'dada@acme.test']);
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);
        $org = Organization::factory()->create([
            'subdomain' => 'acme',
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
        ]);
        $org->users()->attach($user->id, ['role' => 'member']);
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'email' => 'dada@acme.test',
            'inbox' => true,
            'status' => 'active',
        ]);

        return [$user, $org, $mailbox];
    }

    public function test_trashing_sets_trashed_at_and_restore_clears_it(): void
    {
        [$user, $org, $mailbox] = $this->mailboxWorkspace();
        $thread = Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mailbox->id,
            'is_trashed' => false,
            'trashed_at' => null,
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('inbox.trash', $thread->id))
            ->assertRedirect();

        $trashed = $thread->fresh();
        $this->assertTrue($trashed->is_trashed);
        $this->assertNotNull($trashed->trashed_at);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('inbox.trash', $thread->id))
            ->assertRedirect();

        $restored = $thread->fresh();
        $this->assertFalse($restored->is_trashed);
        $this->assertNull($restored->trashed_at);
    }

    public function test_inbound_reply_restores_trashed_thread_to_inbox(): void
    {
        [, $org, $mailbox] = $this->mailboxWorkspace();

        $thread = Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mailbox->id,
            'subject' => 'Order help',
            'is_trashed' => true,
            'trashed_at' => now()->subDay(),
            'is_archived' => true,
            'is_read' => true,
        ]);
        Message::factory()->create([
            'organization_id' => $org->id,
            'thread_id' => $thread->id,
            'mailbox_id' => $mailbox->id,
            'direction' => 'inbound',
            'status' => 'received',
            'message_id_header' => '<first@example.com>',
            'subject' => 'Order help',
        ]);

        $this->withHeader('X-MailDesk-Inbound-Secret', 'generic-secret')
            ->postJson('/api/v1/inbound/generic', [
                'from' => 'Jane <jane@example.com>',
                'to' => ['dada@acme.test'],
                'subject' => 'Re: Order help',
                'text' => 'Any update?',
                'message_id' => '<reply@example.com>',
                'in_reply_to' => '<first@example.com>',
            ])
            ->assertCreated();

        $fresh = $thread->fresh();
        $this->assertFalse($fresh->is_trashed);
        $this->assertNull($fresh->trashed_at);
        $this->assertFalse($fresh->is_archived);
        $this->assertFalse($fresh->is_read);
        $this->assertSame(2, $fresh->message_count);
    }

    public function test_empty_trash_deletes_all_scoped_trashed_threads(): void
    {
        [$user, $org, $mailbox] = $this->mailboxWorkspace();
        $other = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'support@acme.test',
            'inbox' => true,
            'status' => 'active',
        ]);

        $mine = Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mailbox->id,
            'is_trashed' => true,
            'trashed_at' => now(),
        ]);
        Message::factory()->create([
            'organization_id' => $org->id,
            'thread_id' => $mine->id,
            'mailbox_id' => $mailbox->id,
        ]);
        $theirs = Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $other->id,
            'is_trashed' => true,
            'trashed_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->delete(route('trash.empty'))
            ->assertRedirect(route('trash'));

        $this->assertDatabaseMissing('threads', ['id' => $mine->id]);
        $this->assertDatabaseHas('threads', ['id' => $theirs->id]);
    }

    public function test_trash_page_includes_retention_days(): void
    {
        [$user, $org] = $this->mailboxWorkspace();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('trash'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('folder', 'trash')
                ->where('trashRetentionDays', 30));
    }

    public function test_purge_command_deletes_threads_past_retention(): void
    {
        [, $org, $mailbox] = $this->mailboxWorkspace();

        $old = Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mailbox->id,
            'is_trashed' => true,
            'trashed_at' => now()->subDays(31),
        ]);
        Message::factory()->create([
            'organization_id' => $org->id,
            'thread_id' => $old->id,
            'mailbox_id' => $mailbox->id,
        ]);
        $recent = Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mailbox->id,
            'is_trashed' => true,
            'trashed_at' => now()->subDays(5),
        ]);

        Artisan::call('inbox:purge-trash', ['--days' => 30]);

        $this->assertDatabaseMissing('threads', ['id' => $old->id]);
        $this->assertDatabaseHas('threads', ['id' => $recent->id]);
    }

    public function test_purge_dry_run_does_not_delete(): void
    {
        [, $org, $mailbox] = $this->mailboxWorkspace();

        $old = Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mailbox->id,
            'is_trashed' => true,
            'trashed_at' => now()->subDays(40),
        ]);

        Artisan::call('inbox:purge-trash', ['--days' => 30, '--dry-run' => true]);

        $this->assertDatabaseHas('threads', ['id' => $old->id]);
    }
}
