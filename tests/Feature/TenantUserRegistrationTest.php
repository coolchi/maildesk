<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Organization;
use App\Models\User;
use App\Support\UserRegistrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantUserRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Organization, 2: Domain}
     */
    private function ownerWorkspace(array $registration = []): array
    {
        config([
            'maildesk.base_domain' => 'maildesk.test',
            'maildesk.central_domains' => ['maildesk.test'],
            'app.url' => 'http://maildesk.test',
        ]);

        $owner = User::factory()->create();
        $org = Organization::factory()->create(['subdomain' => 'acme']);
        $settings = $org->settings ?? [];
        $settings['user_registration'] = UserRegistrationSettings::normalize(array_merge(
            UserRegistrationSettings::defaults(),
            $registration,
        ));
        $org->update(['settings' => $settings]);
        $org->users()->attach($owner->id, ['role' => 'owner']);
        $domain = Domain::factory()->verified()->create([
            'organization_id' => $org->id,
            'name' => 'acme.test',
        ]);

        return [$owner, $org->fresh(), $domain];
    }

    public function test_join_is_unavailable_when_registration_disabled(): void
    {
        $this->ownerWorkspace(['enabled' => false]);

        $this->get('http://acme.maildesk.test/join')
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $this->post('http://acme.maildesk.test/join', [
            'name' => 'Sam Student',
            'local' => 'sam',
            'domain' => 'acme.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');
    }

    public function test_auto_registration_creates_active_user_and_logs_in(): void
    {
        $this->ownerWorkspace([
            'enabled' => true,
            'approval' => 'auto',
            'default_inbox' => true,
            'default_role' => 'staff',
        ]);

        $this->get('http://acme.maildesk.test/join')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/TenantJoin')
                ->where('requiresApproval', false)
                ->has('domains', 1));

        $this->post('http://acme.maildesk.test/join', [
            'name' => 'Sam Student',
            'local' => 'sam',
            'domain' => 'acme.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect();

        $user = User::query()->where('email', 'sam@acme.test')->firstOrFail();
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('mailboxes', [
            'email' => 'sam@acme.test',
            'user_id' => $user->id,
            'status' => 'active',
            'role' => 'staff',
            'inbox' => 1,
        ]);
        $this->assertDatabaseHas('organization_user', [
            'user_id' => $user->id,
            'role' => 'member',
        ]);
    }

    public function test_manual_registration_creates_pending_user_who_cannot_log_in_until_approved(): void
    {
        [$owner, $org] = $this->ownerWorkspace([
            'enabled' => true,
            'approval' => 'manual',
        ]);

        $this->post('http://acme.maildesk.test/join', [
            'name' => 'Pat Pending',
            'local' => 'pat',
            'domain' => 'acme.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $this->assertGuest();

        $user = User::query()->where('email', 'pat@acme.test')->firstOrFail();
        $mailbox = Mailbox::query()->where('email', 'pat@acme.test')->firstOrFail();
        $this->assertSame('pending', $mailbox->status);

        $this->post('http://acme.maildesk.test/login', [
            'email' => 'pat@acme.test',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();

        $this->actingAs($owner)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('users.approve', $mailbox))
            ->assertRedirect();

        $this->assertSame('active', $mailbox->fresh()->status);

        $this->post(route('logout'));
        $this->post('http://acme.maildesk.test/login', [
            'email' => 'pat@acme.test',
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_reject_removes_pending_registration(): void
    {
        [$owner, $org] = $this->ownerWorkspace([
            'enabled' => true,
            'approval' => 'manual',
        ]);

        $this->post('http://acme.maildesk.test/join', [
            'name' => 'Rejected Person',
            'local' => 'rej',
            'domain' => 'acme.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect();

        $mailbox = Mailbox::query()->where('email', 'rej@acme.test')->firstOrFail();
        $userId = $mailbox->user_id;

        $this->actingAs($owner)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('users.reject', $mailbox))
            ->assertRedirect();

        $this->assertDatabaseMissing('mailboxes', ['id' => $mailbox->id]);
        $this->assertDatabaseMissing('users', ['id' => $userId]);
    }

    public function test_registration_rejects_unverified_domain_and_duplicates(): void
    {
        $this->ownerWorkspace(['enabled' => true, 'approval' => 'auto']);

        $this->post('http://acme.maildesk.test/join', [
            'name' => 'Bad Domain',
            'local' => 'bad',
            'domain' => 'other.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('domain');

        $this->post('http://acme.maildesk.test/join', [
            'name' => 'First',
            'local' => 'dup',
            'domain' => 'acme.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect();

        $this->post(route('logout'));

        $this->post('http://acme.maildesk.test/join', [
            'name' => 'Second',
            'local' => 'dup',
            'domain' => 'acme.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('local');
    }

    public function test_non_team_cannot_approve_pending_users(): void
    {
        [, $org] = $this->ownerWorkspace(['enabled' => true, 'approval' => 'manual']);

        $this->post('http://acme.maildesk.test/join', [
            'name' => 'Pending',
            'local' => 'wait',
            'domain' => 'acme.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect();

        $mailbox = Mailbox::query()->where('email', 'wait@acme.test')->firstOrFail();

        $member = User::factory()->create(['email' => 'staff@acme.test']);
        $org->users()->attach($member->id, ['role' => 'member']);
        Mailbox::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $member->id,
            'email' => 'staff@acme.test',
            'status' => 'active',
            'inbox' => true,
        ]);

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('users.approve', $mailbox))
            ->assertForbidden();

        $this->assertSame('pending', $mailbox->fresh()->status);
    }

    public function test_team_can_save_user_registration_settings(): void
    {
        [$owner, $org] = $this->ownerWorkspace(['enabled' => false]);

        $this->actingAs($owner)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('settings', 'users'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Index')
                ->where('tab', 'users')
                ->where('settings.user_registration.enabled', false)
                ->where('joinUrl', 'http://acme.maildesk.test/join'));

        $this->actingAs($owner)
            ->withSession(['current_organization_id' => $org->id])
            ->put(route('settings.update'), [
                'user_registration' => [
                    'enabled' => true,
                    'approval' => 'manual',
                    'default_role' => 'developer',
                    'default_inbox' => true,
                    'default_transactional' => true,
                    'default_marketing' => false,
                ],
            ])
            ->assertRedirect();

        $settings = UserRegistrationSettings::for($org->fresh());
        $this->assertTrue($settings['enabled']);
        $this->assertSame('manual', $settings['approval']);
        $this->assertSame('developer', $settings['default_role']);
        $this->assertTrue($settings['default_transactional']);
    }

    public function test_unaffiliated_user_can_open_join_and_is_signed_out(): void
    {
        $this->ownerWorkspace(['enabled' => true, 'approval' => 'auto']);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get('http://acme.maildesk.test/join')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/TenantJoin'));

        $this->assertGuest();
    }

    public function test_unaffiliated_user_visiting_workspace_is_sent_to_login(): void
    {
        $this->ownerWorkspace(['enabled' => false]);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get('http://acme.maildesk.test/inbox')
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertTrue(session()->has('status'));
    }
}
