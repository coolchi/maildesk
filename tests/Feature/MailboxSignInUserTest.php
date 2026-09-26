<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MailboxSignInUserTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Organization, 2: Domain}
     */
    private function ownerWorkspace(): array
    {
        $owner = User::factory()->create();
        $org = Organization::factory()->create(['subdomain' => 'acme']);
        $org->users()->attach($owner->id, ['role' => 'owner']);
        $domain = Domain::factory()->verified()->create([
            'organization_id' => $org->id,
            'name' => 'acme.test',
        ]);

        return [$owner, $org, $domain];
    }

    public function test_team_can_create_sign_in_mailbox_user(): void
    {
        [$owner, $org] = $this->ownerWorkspace();

        $this->actingAs($owner)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('users.store'), [
                'name' => 'Sherif',
                'local' => 'sherif',
                'domain' => 'acme.test',
                'role' => 'staff',
                'inbox' => true,
                'transactional' => false,
                'marketing' => false,
                'limit' => 500,
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'sherif@acme.test', 'name' => 'Sherif']);
        $user = User::query()->where('email', 'sherif@acme.test')->firstOrFail();
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'member',
        ]);
        $this->assertDatabaseHas('mailboxes', [
            'organization_id' => $org->id,
            'email' => 'sherif@acme.test',
            'user_id' => $user->id,
            'inbox' => 1,
        ]);

        $this->post(route('logout'));
        $this->post(route('login'), [
            'email' => 'sherif@acme.test',
            'password' => 'password',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_member_with_inbox_only_cannot_open_settings_or_broadcasts(): void
    {
        [$owner, $org] = $this->ownerWorkspace();
        $member = User::factory()->create(['email' => 'staff@acme.test', 'password' => 'password']);
        $org->users()->attach($member->id, ['role' => 'member']);
        Mailbox::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $member->id,
            'email' => 'staff@acme.test',
            'inbox' => true,
            'transactional' => false,
            'marketing' => false,
            'status' => 'active',
        ]);

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('inbox'))
            ->assertOk();

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('broadcasts'))
            ->assertForbidden();

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('settings', 'usage'))
            ->assertForbidden();

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('users'))
            ->assertForbidden();

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('bounced'))
            ->assertForbidden();

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('emails'))
            ->assertForbidden();

        $this->actingAs($owner)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('users'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Users/Index')
                ->where('users.0.can_impersonate', true)
                ->where('users.0.user_id', $member->id));

        $this->actingAs($owner)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('bounced'))
            ->assertOk();

        $this->actingAs($owner)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('emails'))
            ->assertOk();
    }

    public function test_edit_can_set_password_and_create_login_for_legacy_mailbox(): void
    {
        [$owner, $org] = $this->ownerWorkspace();
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'legacy@acme.test',
            'display_name' => 'Legacy',
            'user_id' => null,
            'inbox' => true,
        ]);

        $this->actingAs($owner)
            ->withSession(['current_organization_id' => $org->id])
            ->put(route('users.update', $mailbox), [
                'name' => 'Legacy User',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertRedirect();

        $mailbox->refresh();
        $this->assertNotNull($mailbox->user_id);
        $user = User::query()->findOrFail($mailbox->user_id);
        $this->assertSame('legacy@acme.test', $user->email);
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_inactive_mailbox_cannot_sign_in(): void
    {
        [, $org] = $this->ownerWorkspace();
        $member = User::factory()->create(['email' => 'gone@acme.test', 'password' => 'password']);
        $org->users()->attach($member->id, ['role' => 'member']);
        Mailbox::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $member->id,
            'email' => 'gone@acme.test',
            'status' => 'inactive',
            'inbox' => true,
        ]);

        $this->post(route('login'), [
            'email' => 'gone@acme.test',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_as_from_users_page(): void
    {
        config([
            'maildesk.base_domain' => 'maildesk.test',
            'maildesk.central_domains' => ['maildesk.test'],
            'app.url' => 'http://maildesk.test',
            'session.domain' => '.maildesk.test',
        ]);

        [$owner, $org] = $this->ownerWorkspace();
        $member = User::factory()->create(['email' => 'mia@acme.test']);
        $org->users()->attach($member->id, ['role' => 'member']);
        Mailbox::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $member->id,
            'email' => 'mia@acme.test',
            'inbox' => true,
        ]);

        $this->actingAs($owner)
            ->withSession([
                'current_organization_id' => $org->id,
                'auth.password_confirmed_at' => time(),
            ])
            ->post(route('team.impersonate', $member), [
                'reason' => 'Investigating support ticket #4521',
            ])
            ->assertRedirect('http://acme.maildesk.test/inbox');

        $this->assertAuthenticatedAs($member);
        $this->assertSame($owner->id, session('impersonator_id'));
    }
}
