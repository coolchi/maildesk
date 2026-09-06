<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Support\Header;
use Tests\TestCase;

class WorkspaceSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_switch_workspace_on_same_host_when_session_domain_is_null(): void
    {
        config([
            'maildesk.base_domain' => 'maildesk.test',
            'maildesk.central_domains' => ['maildesk.test'],
            'app.url' => 'http://maildesk.test',
            'session.domain' => null,
        ]);

        $user = User::factory()->create();
        $org = Organization::factory()->create(['subdomain' => 'harbor']);
        $org->users()->attach($user->id, ['role' => 'owner']);

        $response = $this->actingAs($user)
            ->post(route('workspace.switch', $org), [
                'redirect' => '/emails',
            ]);

        $response->assertRedirect('/emails');
        $this->assertEquals($org->id, session('current_organization_id'));
        $this->assertFalse(app(TenantResolver::class)->sharesSessionAcrossSubdomains());
    }

    public function test_user_is_redirected_to_subdomain_when_session_domain_shared(): void
    {
        config([
            'maildesk.base_domain' => 'maildesk.test',
            'maildesk.central_domains' => ['maildesk.test'],
            'app.url' => 'http://maildesk.test',
            'session.domain' => '.maildesk.test',
        ]);

        $user = User::factory()->create();
        $org = Organization::factory()->create(['subdomain' => 'harbor']);
        $org->users()->attach($user->id, ['role' => 'owner']);

        $this->assertTrue(app(TenantResolver::class)->sharesSessionAcrossSubdomains());

        $response = $this->actingAs($user)
            ->post(route('workspace.switch', $org), [
                'redirect' => '/emails',
            ]);

        $response->assertRedirect('http://harbor.maildesk.test/emails');
        $this->assertEquals($org->id, session('current_organization_id'));
    }

    public function test_inertia_switch_uses_location_header_for_subdomain(): void
    {
        config([
            'maildesk.base_domain' => 'maildesk.test',
            'maildesk.central_domains' => ['maildesk.test'],
            'app.url' => 'http://maildesk.test',
            'session.domain' => '.maildesk.test',
        ]);

        $user = User::factory()->create();
        $org = Organization::factory()->create(['subdomain' => 'harbor']);
        $org->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->withHeaders([Header::INERTIA => 'true'])
            ->post(route('workspace.switch', $org), [
                'redirect' => '/emails',
            ])
            ->assertStatus(409)
            ->assertHeader(Header::LOCATION, 'http://harbor.maildesk.test/emails');
    }

    public function test_login_still_works_with_shared_session_domain(): void
    {
        config([
            'session.domain' => '.maildesk.test',
            'session.secure' => false,
            'session.same_site' => 'lax',
            'app.url' => 'http://maildesk.test',
        ]);

        $user = User::factory()->create();

        $this->get('http://maildesk.test/login')->assertOk();

        $this->post('http://maildesk.test/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_works_on_tenant_subdomain_with_shared_session_domain(): void
    {
        config([
            'session.domain' => '.maildesk.test',
            'session.cookie' => 'maildesk_session_v4',
            'session.secure' => false,
            'session.same_site' => 'lax',
            'maildesk.base_domain' => 'maildesk.test',
            'app.url' => 'http://maildesk.test',
        ]);

        $user = User::factory()->create();
        Organization::factory()->create(['subdomain' => 'acme']);

        $this->get('http://acme.maildesk.test/login')->assertOk();

        $this->post('http://acme.maildesk.test/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_non_member_cannot_switch_to_foreign_workspace(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create(['subdomain' => 'secret']);

        $this->actingAs($user)
            ->post(route('workspace.switch', $org))
            ->assertForbidden();
    }
}
