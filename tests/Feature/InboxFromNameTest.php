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

class InboxFromNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_rows_include_sender_display_name_when_available(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'support@in.desk.ng',
        ]);

        $named = Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mailbox->id,
            'last_message_at' => now(),
        ]);
        Message::factory()->create([
            'organization_id' => $org->id,
            'thread_id' => $named->id,
            'direction' => 'inbound',
            'status' => 'received',
            'from_email' => 'coolchi01@gmail.com',
            'from_name' => 'Sherif Coolchi',
            'to' => ['support@in.desk.ng'],
        ]);

        $unnamed = Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mailbox->id,
            'last_message_at' => now()->subHour(),
        ]);
        Message::factory()->create([
            'organization_id' => $org->id,
            'thread_id' => $unnamed->id,
            'direction' => 'inbound',
            'status' => 'received',
            'from_email' => 'noreply@example.com',
            'from_name' => null,
            'to' => ['support@in.desk.ng'],
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('inbox'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inbox/Index')
                ->where('threads', function ($threads) use ($named, $unnamed) {
                    $namedRow = collect($threads)->firstWhere('id', $named->id);
                    $unnamedRow = collect($threads)->firstWhere('id', $unnamed->id);

                    return $namedRow['from'] === 'coolchi01@gmail.com'
                        && $namedRow['from_email'] === 'coolchi01@gmail.com'
                        && $namedRow['from_name'] === 'Sherif Coolchi'
                        && $unnamedRow['from'] === 'noreply@example.com'
                        && $unnamedRow['from_name'] === null;
                }));
    }
}
