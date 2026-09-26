<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MailboxDataScopeTest extends TestCase
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

    public function test_mailbox_member_only_sees_their_inbox_threads(): void
    {
        [, $member, $org, $mine, $other] = $this->workspaceWithTwoMailboxes();

        $mineThread = Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mine->id,
            'subject' => 'Mine',
            'last_message_at' => now(),
            'is_read' => false,
        ]);
        Message::factory()->create([
            'organization_id' => $org->id,
            'thread_id' => $mineThread->id,
            'mailbox_id' => $mine->id,
            'direction' => 'inbound',
            'status' => 'received',
            'from_email' => 'customer@example.com',
            'to' => ['dada@acme.test'],
        ]);

        $otherThread = Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $other->id,
            'subject' => 'Support desk',
            'last_message_at' => now()->subMinute(),
            'is_read' => false,
        ]);
        Message::factory()->create([
            'organization_id' => $org->id,
            'thread_id' => $otherThread->id,
            'mailbox_id' => $other->id,
            'direction' => 'inbound',
            'status' => 'received',
            'from_email' => 'someone@example.com',
            'to' => ['support@acme.test'],
        ]);

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('inbox'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inbox/Index')
                ->has('threads', 1)
                ->where('threads.0.id', $mineThread->id)
                ->where('inbox_unread', 1)
                ->where('auth.mailbox_id', $mine->id)
                ->where('tenant.sending_from', ['dada@acme.test']));

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('inbox.show', $otherThread))
            ->assertNotFound();
    }

    public function test_team_owner_still_sees_all_inbox_threads(): void
    {
        [$owner, , $org, $mine, $other] = $this->workspaceWithTwoMailboxes();

        Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mine->id,
            'last_message_at' => now(),
            'is_read' => true,
        ]);
        Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $other->id,
            'last_message_at' => now()->subMinute(),
            'is_read' => false,
        ]);

        $this->actingAs($owner)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('inbox'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inbox/Index')
                ->has('threads', 2)
                ->where('inbox_unread', 1)
                ->where('auth.mailbox_id', null));
    }

    public function test_mailbox_member_cannot_open_emails_and_cannot_send_as_others(): void
    {
        [, $member, $org] = $this->workspaceWithTwoMailboxes();

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('emails'))
            ->assertForbidden();

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('emails.store'), [
                'from' => 'support@acme.test',
                'to' => 'customer@example.com',
                'subject' => 'Nope',
                'text' => 'Hello',
                'stay' => true,
            ])
            ->assertSessionHasErrors('from');
    }
}
