<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Domain;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantWorkspaceDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_only_sees_current_workspace_domains(): void
    {
        $user = User::factory()->create();
        $own = Organization::factory()->create(['name' => 'Own Org']);
        $other = Organization::factory()->create(['name' => 'Other Org']);
        $own->users()->attach($user->id, ['role' => 'owner']);

        Domain::factory()->create(['organization_id' => $own->id, 'name' => 'own.test']);
        Domain::factory()->create(['organization_id' => $other->id, 'name' => 'other.test']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $own->id])
            ->get(route('domains'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Domains/Index')
                ->has('domains', 1)
                ->where('domains.0.name', 'own.test'));
    }

    public function test_member_cannot_view_foreign_domain(): void
    {
        $user = User::factory()->create();
        $own = Organization::factory()->create();
        $other = Organization::factory()->create();
        $own->users()->attach($user->id, ['role' => 'owner']);

        $foreign = Domain::factory()->create(['organization_id' => $other->id]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $own->id])
            ->get(route('domains.show', $foreign))
            ->assertNotFound();
    }

    public function test_member_can_create_domain_for_current_workspace(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('domains.store'), ['name' => 'mail.example.com'])
            ->assertRedirect();

        $this->assertDatabaseHas('domains', [
            'organization_id' => $org->id,
            'name' => 'mail.example.com',
            'status' => 'pending',
        ]);
    }

    public function test_emails_are_scoped_to_current_workspace(): void
    {
        $user = User::factory()->create();
        $own = Organization::factory()->create();
        $other = Organization::factory()->create();
        $own->users()->attach($user->id, ['role' => 'owner']);

        $ownMessage = Message::factory()->create([
            'organization_id' => $own->id,
            'subject' => 'Own subject',
        ]);
        Message::factory()->create([
            'organization_id' => $other->id,
            'subject' => 'Foreign subject',
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $own->id])
            ->get(route('emails'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Emails/Index')
                ->has('emails', 1)
                ->where('emails.0.subject', 'Own subject'));

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $own->id])
            ->get(route('emails.show', $ownMessage->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Emails/Show')
                ->where('email.subject', 'Own subject'));
    }

    public function test_member_cannot_view_foreign_email(): void
    {
        $user = User::factory()->create();
        $own = Organization::factory()->create();
        $other = Organization::factory()->create();
        $own->users()->attach($user->id, ['role' => 'owner']);

        $foreign = Message::factory()->create(['organization_id' => $other->id]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $own->id])
            ->get(route('emails.show', $foreign->uuid))
            ->assertNotFound();
    }

    public function test_api_keys_and_inbox_are_scoped_to_workspace(): void
    {
        $user = User::factory()->create();
        $own = Organization::factory()->create();
        $other = Organization::factory()->create();
        $own->users()->attach($user->id, ['role' => 'owner']);

        ApiKey::issue($own, 'Own Key', $user);
        ApiKey::issue($other, 'Other Key', $user);
        Thread::factory()->create([
            'organization_id' => $own->id,
            'subject' => 'Own thread',
        ]);
        Thread::factory()->create([
            'organization_id' => $other->id,
            'subject' => 'Other thread',
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $own->id])
            ->get(route('api-keys'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ApiKeys/Index')
                ->has('keys', 1)
                ->where('keys.0.name', 'Own Key'));

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $own->id])
            ->get(route('inbox'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Inbox/Index')
                ->has('threads', 1)
                ->where('threads.0.subject', 'Own thread'));
    }

    public function test_member_cannot_delete_foreign_api_key(): void
    {
        $user = User::factory()->create();
        $own = Organization::factory()->create();
        $other = Organization::factory()->create();
        $own->users()->attach($user->id, ['role' => 'owner']);

        $foreign = ApiKey::issue($other, 'Secret', $user)['model'];

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $own->id])
            ->delete(route('api-keys.destroy', $foreign))
            ->assertNotFound();
    }
}
