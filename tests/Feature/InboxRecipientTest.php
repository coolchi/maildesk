<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class InboxRecipientTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_rows_include_the_receiver_address(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $mailbox = Mailbox::factory()->create(['organization_id' => $org->id, 'email' => 'support@in.desk.ng']);

        $withTo = Thread::factory()->create(['organization_id' => $org->id, 'mailbox_id' => $mailbox->id, 'last_message_at' => now()]);
        Message::factory()->create([
            'organization_id' => $org->id, 'thread_id' => $withTo->id, 'direction' => 'inbound',
            'status' => 'received', 'from_email' => 'jane@example.com', 'to' => ['support@in.desk.ng', 'sales@in.desk.ng'],
        ]);

        // No recipients stored: falls back to the mailbox address.
        $noTo = Thread::factory()->create(['organization_id' => $org->id, 'mailbox_id' => $mailbox->id, 'last_message_at' => now()->subHour()]);
        Message::factory()->create([
            'organization_id' => $org->id, 'thread_id' => $noTo->id, 'direction' => 'inbound',
            'status' => 'received', 'from_email' => 'tom@example.org', 'to' => [],
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $org->id])
            ->get(route('inbox'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inbox/Index')
                ->where('threads', fn ($threads) => collect($threads)->firstWhere('id', $withTo->id)['to'] === 'support@in.desk.ng, sales@in.desk.ng'
                    && collect($threads)->firstWhere('id', $withTo->id)['from'] === 'jane@example.com'
                    && collect($threads)->firstWhere('id', $withTo->id)['messages'][0]['to'] === 'support@in.desk.ng, sales@in.desk.ng'
                    && collect($threads)->firstWhere('id', $noTo->id)['to'] === 'support@in.desk.ng'));
    }
}
