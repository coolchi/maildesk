<?php

namespace Tests\Feature;

use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\OrganizationHost;
use App\Models\User;
use App\Services\TenantResolver;
use App\Support\UserRegistrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_finds_organization_by_subdomain_host(): void
    {
        $provider = MailProvider::factory()->create(['key' => 'resend', 'status' => 'active']);
        $org = Organization::factory()->create([
            'subdomain' => 'acme',
            'mail_provider_id' => $provider->id,
        ]);
        OrganizationHost::factory()->create([
            'organization_id' => $org->id,
            'subdomain' => 'acme',
            'host' => 'acme.maildesk.test',
            'status' => 'active',
        ]);

        config([
            'maildesk.base_domain' => 'maildesk.test',
            'maildesk.central_domains' => ['maildesk.test'],
        ]);

        $resolved = app(TenantResolver::class)->resolveFromHost('acme.maildesk.test');

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is($org));
    }

    public function test_member_can_access_tenant_subdomain(): void
    {
        config([
            'maildesk.base_domain' => 'maildesk.test',
            'maildesk.central_domains' => ['maildesk.test'],
            'app.url' => 'http://maildesk.test',
        ]);

        $provider = MailProvider::factory()->create(['status' => 'active']);
        $org = Organization::factory()->create([
            'subdomain' => 'acme',
            'mail_provider_id' => $provider->id,
        ]);
        $user = User::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->get('http://acme.maildesk.test/emails')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tenant.current.id', $org->id)
                ->where('tenant.host_locked', true)
                ->where('tenant.can_send', true));
    }

    public function test_non_member_is_sent_to_login_on_tenant_host(): void
    {
        config([
            'maildesk.base_domain' => 'maildesk.test',
            'maildesk.central_domains' => ['maildesk.test'],
            'app.url' => 'http://maildesk.test',
        ]);

        Organization::factory()->create(['subdomain' => 'acme']);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get('http://acme.maildesk.test/emails')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_central_host_uses_session_workspace(): void
    {
        config([
            'maildesk.base_domain' => 'maildesk.test',
            'maildesk.central_domains' => ['maildesk.test'],
            'app.url' => 'http://maildesk.test',
        ]);

        $user = User::factory()->create();
        $first = Organization::factory()->create(['name' => 'Alpha', 'subdomain' => 'alpha']);
        $second = Organization::factory()->create(['name' => 'Beta', 'subdomain' => 'beta']);
        $first->users()->attach($user->id, ['role' => 'owner']);
        $second->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $second->id])
            ->get('http://maildesk.test/emails')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tenant.current.id', $second->id)
                ->where('tenant.host_locked', false)
                ->has('tenant.workspaces', 2));
    }

    public function test_tenant_home_redirects_guests_to_login(): void
    {
        config([
            'maildesk.base_domain' => 'maildesk.test',
            'maildesk.central_domains' => ['maildesk.test'],
            'app.url' => 'http://maildesk.test',
        ]);

        Organization::factory()->create(['subdomain' => 'acme']);

        $this->get('http://acme.maildesk.test/')
            ->assertRedirect(route('login'));

        $this->get('http://maildesk.test/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Welcome'));
    }

    public function test_tenant_register_redirects_to_login_when_join_disabled(): void
    {
        config([
            'maildesk.base_domain' => 'maildesk.test',
            'maildesk.central_domains' => ['maildesk.test'],
            'app.url' => 'http://maildesk.test',
        ]);

        Organization::factory()->create(['subdomain' => 'acme']);

        $this->get('http://acme.maildesk.test/register')
            ->assertRedirect(route('login'));

        $this->post('http://acme.maildesk.test/register', [
            'name' => 'New Admin',
            'email' => 'new@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_tenant_register_redirects_to_join_when_join_enabled(): void
    {
        config([
            'maildesk.base_domain' => 'maildesk.test',
            'maildesk.central_domains' => ['maildesk.test'],
            'app.url' => 'http://maildesk.test',
        ]);

        $org = Organization::factory()->create([
            'subdomain' => 'acme',
            'settings' => [
                'user_registration' => [
                    'enabled' => true,
                    'approval' => 'auto',
                    'default_role' => 'staff',
                    'default_inbox' => true,
                    'default_transactional' => false,
                    'default_marketing' => false,
                ],
            ],
        ]);

        $this->get('http://acme.maildesk.test/register')
            ->assertRedirect(route('tenant.join'));

        $this->post('http://acme.maildesk.test/register', [
            'name' => 'New Admin',
            'email' => 'new@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('tenant.join'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
        $this->assertTrue(UserRegistrationSettings::enabled($org));
    }
}
