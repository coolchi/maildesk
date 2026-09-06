<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_only_sees_current_workspace_webhooks(): void
    {
        $user = User::factory()->create();
        $own = Organization::factory()->create();
        $other = Organization::factory()->create();
        $own->users()->attach($user->id, ['role' => 'owner']);

        Webhook::factory()->create(['organization_id' => $own->id, 'url' => 'https://own.example/hook']);
        Webhook::factory()->create(['organization_id' => $other->id, 'url' => 'https://other.example/hook']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $own->id])
            ->get(route('webhooks'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Webhooks/Index')
                ->has('webhooks', 1)
                ->where('webhooks.0.endpoint', 'https://own.example/hook'));
    }

    public function test_member_can_create_and_delete_webhook(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $response = $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('webhooks.store'), [
                'url' => 'https://api.example.com/hooks',
                'events' => ['email.delivered', 'email.bounced'],
            ]);

        $webhook = Webhook::query()->where('organization_id', $org->id)->first();
        $this->assertNotNull($webhook);
        $response->assertRedirect(route('webhooks.show', $webhook));

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->delete(route('webhooks.destroy', $webhook))
            ->assertRedirect(route('webhooks'));

        $this->assertDatabaseMissing('webhooks', ['id' => $webhook->id]);
    }

    public function test_member_cannot_view_foreign_webhook(): void
    {
        $user = User::factory()->create();
        $own = Organization::factory()->create();
        $other = Organization::factory()->create();
        $own->users()->attach($user->id, ['role' => 'owner']);
        $foreign = Webhook::factory()->create(['organization_id' => $other->id]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $own->id])
            ->get(route('webhooks.show', $foreign))
            ->assertNotFound();
    }

    public function test_member_can_toggle_webhook(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $webhook = Webhook::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->put(route('webhooks.update', $webhook), ['is_active' => false])
            ->assertRedirect();

        $this->assertDatabaseHas('webhooks', [
            'id' => $webhook->id,
            'is_active' => false,
        ]);
    }
}
