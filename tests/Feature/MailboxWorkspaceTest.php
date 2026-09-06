<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailboxWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_list_and_create_mailbox_users(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        Domain::factory()->verified()->create([
            'organization_id' => $org->id,
            'name' => 'acme.test',
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('users'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Users/Index'));

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('users.store'), [
                'name' => 'Support Desk',
                'local' => 'support',
                'domain' => 'acme.test',
                'role' => 'staff',
                'inbox' => true,
                'transactional' => false,
                'marketing' => false,
                'limit' => 500,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('mailboxes', [
            'organization_id' => $org->id,
            'email' => 'support@acme.test',
            'role' => 'staff',
        ]);
    }

    public function test_member_cannot_update_foreign_mailbox(): void
    {
        $user = User::factory()->create();
        $own = Organization::factory()->create();
        $other = Organization::factory()->create();
        $own->users()->attach($user->id, ['role' => 'owner']);
        $foreign = Mailbox::factory()->create(['organization_id' => $other->id]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $own->id])
            ->put(route('users.update', $foreign), ['status' => 'inactive'])
            ->assertNotFound();
    }
}
