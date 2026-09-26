<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MailUserTrashTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: User, 2: Organization, 3: Mailbox, 4: Mailbox}
     */
    private function workspaceWithTwoMailboxes(): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['email' => 'dada@acme.test']);
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);
        $org = Organization::factory()->create([
            'subdomain' => 'acme',
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
        ]);
        $org->users()->attach($owner->id, ['role' => 'owner']);
        $org->users()->attach($member->id, ['role' => 'member']);

        $mine = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $member->id,
            'email' => 'dada@acme.test',
            'inbox' => true,
            'status' => 'active',
        ]);
        $other = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'support@acme.test',
            'inbox' => true,
            'status' => 'active',
        ]);

        return [$owner, $member, $org, $mine, $other];
    }

    public function test_trash_hides_from_inbox_and_shows_on_trash(): void
    {
        [, $member, $org, $mine] = $this->workspaceWithTwoMailboxes();

        $thread = Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mine->id,
            'subject' => 'Bin me',
            'last_message_at' => now(),
            'is_archived' => false,
            'is_trashed' => false,
            'is_read' => true,
        ]);

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('inbox.trash', $thread->id))
            ->assertRedirect();

        $this->assertTrue($thread->fresh()->is_trashed);

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('inbox'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('threads', 0));

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('trash'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inbox/Index')
                ->where('folder', 'trash')
                ->has('threads', 1)
                ->where('threads.0.id', $thread->id)
                ->where('threads.0.is_trashed', true));

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('inbox.trash', $thread->id))
            ->assertRedirect();

        $this->assertFalse($thread->fresh()->is_trashed);

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('inbox'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('threads', 1));
    }

    public function test_trashed_archived_thread_restores_to_archive(): void
    {
        [, $member, $org, $mine] = $this->workspaceWithTwoMailboxes();

        $thread = Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mine->id,
            'is_archived' => true,
            'is_trashed' => false,
            'is_read' => true,
            'last_message_at' => now(),
        ]);

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('inbox.trash', $thread->id))
            ->assertRedirect();

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('inbox.trash', $thread->id))
            ->assertRedirect();

        $fresh = $thread->fresh();
        $this->assertFalse($fresh->is_trashed);
        $this->assertTrue($fresh->is_archived);

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('archive'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('folder', 'archive')
                ->has('threads', 1)
                ->where('threads.0.id', $thread->id));
    }

    public function test_permanent_delete_removes_thread_and_messages(): void
    {
        [, $member, $org, $mine] = $this->workspaceWithTwoMailboxes();

        $thread = Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mine->id,
            'is_trashed' => true,
            'is_read' => true,
        ]);
        $message = Message::factory()->create([
            'organization_id' => $org->id,
            'thread_id' => $thread->id,
            'mailbox_id' => $mine->id,
            'direction' => 'inbound',
            'status' => 'received',
        ]);

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->delete(route('inbox.destroy', $thread->id))
            ->assertRedirect(route('trash'));

        $this->assertDatabaseMissing('threads', ['id' => $thread->id]);
        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
    }

    public function test_cannot_permanently_delete_thread_that_is_not_trashed(): void
    {
        [, $member, $org, $mine] = $this->workspaceWithTwoMailboxes();

        $thread = Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mine->id,
            'is_trashed' => false,
        ]);

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->delete(route('inbox.destroy', $thread->id))
            ->assertNotFound();

        $this->assertDatabaseHas('threads', ['id' => $thread->id]);
    }

    public function test_member_cannot_trash_another_mailbox_thread(): void
    {
        [, $member, $org, , $other] = $this->workspaceWithTwoMailboxes();

        $thread = Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $other->id,
            'is_trashed' => false,
        ]);

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('inbox.trash', $thread->id))
            ->assertNotFound();
    }

    public function test_trashed_unread_threads_are_excluded_from_inbox_unread(): void
    {
        [, $member, $org, $mine] = $this->workspaceWithTwoMailboxes();

        Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mine->id,
            'is_trashed' => true,
            'is_archived' => false,
            'is_read' => false,
            'last_message_at' => now(),
        ]);
        Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mine->id,
            'is_trashed' => false,
            'is_archived' => false,
            'is_read' => false,
            'last_message_at' => now()->subMinute(),
        ]);

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->getJson(route('inbox.sync'))
            ->assertOk()
            ->assertJson(['unread' => 1]);
    }
}
