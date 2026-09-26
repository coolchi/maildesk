<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\MailDraft;
use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MailUserFeaturesTest extends TestCase
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

    public function test_archive_hides_from_inbox_and_shows_on_archive(): void
    {
        [, $member, $org, $mine] = $this->workspaceWithTwoMailboxes();

        $thread = Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mine->id,
            'subject' => 'Keep me',
            'last_message_at' => now(),
            'is_archived' => false,
            'is_read' => true,
        ]);

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('inbox'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inbox/Index')
                ->where('folder', 'inbox')
                ->has('threads', 1)
                ->where('threads.0.id', $thread->id));

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('inbox.archive', $thread->id))
            ->assertRedirect();

        $this->assertTrue($thread->fresh()->is_archived);

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('inbox'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('threads', 0));

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('archive'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inbox/Index')
                ->where('folder', 'archive')
                ->has('threads', 1)
                ->where('threads.0.id', $thread->id));

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('inbox.archive', $thread->id))
            ->assertRedirect();

        $this->assertFalse($thread->fresh()->is_archived);
    }

    public function test_member_cannot_archive_another_mailbox_thread(): void
    {
        [, $member, $org, , $other] = $this->workspaceWithTwoMailboxes();

        $thread = Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $other->id,
            'is_archived' => false,
        ]);

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('inbox.archive', $thread->id))
            ->assertNotFound();
    }

    public function test_drafts_are_scoped_to_the_current_user(): void
    {
        [$owner, $member, $org, $mine] = $this->workspaceWithTwoMailboxes();

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('drafts.store'), [
                'from' => 'dada@acme.test',
                'to' => 'cara@example.com',
                'subject' => 'Later',
                'html' => '<p>Draft body</p>',
            ])
            ->assertRedirect()
            ->assertSessionHas('draft_id');

        $draft = MailDraft::query()->firstOrFail();
        $this->assertSame($member->id, $draft->user_id);
        $this->assertSame($org->id, $draft->organization_id);
        $this->assertSame($mine->id, $draft->mailbox_id);

        MailDraft::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $owner->id,
            'subject' => 'Owner draft',
        ]);

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('drafts'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Drafts/Index')
                ->has('drafts', 1)
                ->where('drafts.0.subject', 'Later'));

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->put(route('drafts.update', $draft), [
                'subject' => 'Updated later',
                'html' => '<p>Updated</p>',
            ])
            ->assertRedirect()
            ->assertSessionHas('draft_id', $draft->id);

        $this->assertSame('Updated later', $draft->fresh()->subject);

        $foreign = MailDraft::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $owner->id,
        ]);

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->delete(route('drafts.destroy', $foreign))
            ->assertNotFound();

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->delete(route('drafts.destroy', $draft))
            ->assertRedirect();

        $this->assertDatabaseMissing('mail_drafts', ['id' => $draft->id]);
    }

    public function test_sending_deletes_linked_draft(): void
    {
        [, $member, $org] = $this->workspaceWithTwoMailboxes();

        $draft = MailDraft::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $member->id,
            'from' => 'dada@acme.test',
            'to' => 'cara@example.com',
            'subject' => 'Send me',
            'html' => '<p>Hi</p>',
        ]);

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('emails.store'), [
                'from' => 'dada@acme.test',
                'to' => 'cara@example.com',
                'subject' => 'Send me',
                'html' => '<p>Hi</p>',
                'draft_id' => $draft->id,
                'stay' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('mail_drafts', ['id' => $draft->id]);
    }

    public function test_member_remains_forbidden_on_emails_and_bounced(): void
    {
        [, $member, $org] = $this->workspaceWithTwoMailboxes();

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('emails'))
            ->assertForbidden();

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('bounced'))
            ->assertForbidden();
    }
}
