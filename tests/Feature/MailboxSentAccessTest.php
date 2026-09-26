<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MailboxSentAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Organization, 2: Mailbox}
     */
    private function mailboxMember(): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['email' => 'desk@acme.test']);
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);
        $org = Organization::factory()->create([
            'subdomain' => 'acme',
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
        ]);
        $org->users()->attach($owner->id, ['role' => 'owner']);
        $org->users()->attach($member->id, ['role' => 'member']);

        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $member->id,
            'email' => 'desk@acme.test',
            'inbox' => true,
            'status' => 'active',
        ]);

        return [$member, $org, $mailbox];
    }

    public function test_mailbox_member_can_open_sent_list_and_own_message(): void
    {
        [$member, $org, $mailbox] = $this->mailboxMember();

        $message = Message::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mailbox->id,
            'direction' => 'outbound',
            'status' => 'delivered',
            'from_email' => 'desk@acme.test',
            'to' => ['customer@example.com'],
            'subject' => 'Hello customer',
            'sent_at' => now(),
        ]);

        $other = Message::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => Mailbox::factory()->create([
                'organization_id' => $org->id,
                'email' => 'support@acme.test',
            ])->id,
            'direction' => 'outbound',
            'status' => 'delivered',
            'from_email' => 'support@acme.test',
            'to' => ['other@example.com'],
            'subject' => 'Not yours',
            'sent_at' => now()->subMinute(),
        ]);

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('sent'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sent/Index')
                ->has('emails', 1)
                ->where('emails.0.subject', 'Hello customer'));

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('emails.show', $message->uuid))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Emails/Show')
                ->where('email.subject', 'Hello customer')
                ->where('adminView', false));

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('emails.show', $other->uuid))
            ->assertNotFound();

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('emails'))
            ->assertForbidden();
    }

    public function test_team_owner_gets_admin_email_detail_view(): void
    {
        $owner = User::factory()->create();
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);
        $org = Organization::factory()->create([
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
        ]);
        $org->users()->attach($owner->id, ['role' => 'owner']);

        $message = Message::factory()->create([
            'organization_id' => $org->id,
            'direction' => 'outbound',
            'status' => 'delivered',
            'subject' => 'Admin view',
            'sent_at' => now(),
        ]);

        $this->actingAs($owner)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('emails.show', $message->uuid))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Emails/Show')
                ->where('adminView', true));
    }
}
