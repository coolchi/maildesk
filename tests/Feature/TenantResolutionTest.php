<?php

namespace Tests\Feature;

use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\OrganizationHost;
use App\Models\User;
use App\Services\TenantResolver;
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

    public function test_non_member_is_forbidden_on_tenant_host(): void
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
            ->assertForbidden();
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
}
