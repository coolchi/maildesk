<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmailsIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_emails_index_paginates_outbound_mail(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'owner']);

        Message::factory()
            ->count(26)
            ->sequence(fn ($sequence) => [
                'subject' => 'Outbound '.$sequence->index,
                'created_at' => now()->subMinutes(26 - $sequence->index),
                'sent_at' => now()->subMinutes(26 - $sequence->index),
            ])
            ->create([
                'organization_id' => $organization->id,
                'direction' => 'outbound',
                'status' => 'delivered',
                'to' => ['customer@example.com'],
            ]);

        Message::factory()->create([
            'organization_id' => $organization->id,
            'direction' => 'outbound',
            'status' => 'bounced',
            'subject' => 'Bounced receipt',
            'to' => ['gone@example.com'],
            'created_at' => now(),
            'sent_at' => now(),
        ]);

        Message::factory()->inbound()->create([
            'organization_id' => $organization->id,
            'subject' => 'Inbound only',
            'from_email' => 'sender@example.com',
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('emails'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Emails/Index')
                ->has('emails', 15)
                ->where('emails.0.subject', 'Bounced receipt')
                ->where('pagination.total', 27)
                ->where('pagination.current_page', 1)
                ->where('pagination.last_page', 2)
                ->where('filters.tab', 'sending'));

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('emails', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('emails', 12)
                ->where('emails.0.subject', 'Outbound 11')
                ->where('emails.11.subject', 'Outbound 0')
                ->where('pagination.current_page', 2));
    }

    public function test_emails_index_filters_by_tab_status_and_search(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'owner']);

        Message::factory()->create([
            'organization_id' => $organization->id,
            'direction' => 'outbound',
            'status' => 'delivered',
            'subject' => 'Invoice ready',
            'to' => ['pay@example.com'],
        ]);
        Message::factory()->create([
            'organization_id' => $organization->id,
            'direction' => 'outbound',
            'status' => 'bounced',
            'subject' => 'Bounced invoice',
            'to' => ['gone@example.com'],
        ]);
        Message::factory()->inbound()->create([
            'organization_id' => $organization->id,
            'subject' => 'Customer reply',
            'from_email' => 'sender@example.com',
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('emails', ['status' => 'bounced']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('emails', 1)
                ->where('emails.0.subject', 'Bounced invoice')
                ->where('filters.status', 'bounced')
                ->where('pagination.total', 1));

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('emails', ['q' => 'gone@']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('emails', 1)
                ->where('emails.0.subject', 'Bounced invoice'));

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('emails', ['tab' => 'receiving']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('emails', 1)
                ->where('emails.0.subject', 'Customer reply')
                ->where('emails.0.from', 'sender@example.com')
                ->where('filters.tab', 'receiving')
                ->where('pagination.total', 1));
    }
}
