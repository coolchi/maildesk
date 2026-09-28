<?php

namespace Tests\Feature;

use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Support\Header;
use Tests\TestCase;

class WorkspaceCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_create_workspace(): void
    {
        config([
            'maildesk.base_domain' => 'maildesk.test',
            'session.domain' => null,
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('workspaces.store'), [
                'name' => 'Harbor Labs',
                'subdomain' => 'harbor-labs',
            ]);

        $org = Organization::query()->where('name', 'Harbor Labs')->first();
        $this->assertNotNull($org);
        $this->assertTrue($org->users()->whereKey($user->id)->exists());
        $this->assertSame('harbor-labs', $org->subdomain);
        $this->assertDatabaseHas('organization_hosts', [
            'organization_id' => $org->id,
            'host' => 'harbor-labs.maildesk.test',
        ]);
        $this->assertEquals($org->id, session('current_organization_id'));
        $response->assertRedirect('/emails');
    }

    public function test_new_workspace_gets_the_platform_default_provider(): void
    {
        config([
            'maildesk.base_domain' => 'maildesk.test',
            'session.domain' => null,
        ]);

        $provider = MailProvider::factory()->default()->create([
            'driver' => 'resend',
            'status' => 'active',
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('workspaces.store'), [
                'name' => 'Harbor Labs',
                'subdomain' => 'harbor-labs',
            ])
            ->assertRedirect('/emails');

        $org = Organization::query()->where('name', 'Harbor Labs')->first();
        $this->assertSame($provider->id, $org->mail_provider_id);
        $this->assertSame('resend', $org->default_provider);
        $this->assertTrue($org->fresh()->toWorkspaceArray()['providerOk']);
    }

    public function test_create_workspace_uses_inertia_location_when_session_shared(): void
    {
        config([
            'maildesk.base_domain' => 'maildesk.test',
            'session.domain' => '.maildesk.test',
            'app.url' => 'http://maildesk.test',
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeaders([Header::INERTIA => 'true'])
            ->post(route('workspaces.store'), ['name' => 'Nova', 'subdomain' => 'nova'])
            ->assertStatus(409)
            ->assertHeader(Header::LOCATION);
    }
}
