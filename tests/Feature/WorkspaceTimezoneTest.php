<?php

namespace Tests\Feature;

use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);
        $this->org = Organization::factory()->create([
            'mail_provider_id' => $provider->id,
        ]);
        $this->org->users()->attach($this->user->id, ['role' => 'owner']);
    }

    public function test_default_timezone_is_africa_lagos(): void
    {
        $this->assertSame('Africa/Lagos', $this->org->getTimezone());
        $this->assertSame('Africa/Lagos', $this->org->toWorkspaceArray()['timezone']);
    }

    public function test_timezone_can_be_saved_in_settings(): void
    {
        $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->put(route('settings.update'), ['timezone' => 'America/New_York'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->org->refresh();

        $this->assertSame('America/New_York', $this->org->getTimezone());
        $this->assertSame('America/New_York', $this->org->toWorkspaceArray()['timezone']);
    }

    public function test_invalid_timezone_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->put(route('settings.update'), ['timezone' => 'Invalid/Timezone'])
            ->assertSessionHasErrors('timezone');

        $this->org->refresh();

        $this->assertSame('Africa/Lagos', $this->org->getTimezone());
    }

    public function test_timezone_is_returned_on_settings_page(): void
    {
        $this->org->update(['settings' => ['timezone' => 'Europe/London']]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->get(route('settings', 'usage'));

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->component('Settings/Index')
                ->where('settings.timezone', 'Europe/London')
        );
    }

    public function test_timezone_persists_alongside_other_settings(): void
    {
        $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->put(route('settings.update'), [
                'unsubscribe' => ['brand' => 'Acme Corp'],
            ])
            ->assertRedirect();

        $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->put(route('settings.update'), ['timezone' => 'Asia/Tokyo'])
            ->assertRedirect();

        $this->org->refresh();
        $settings = $this->org->settings;

        $this->assertSame('Asia/Tokyo', $settings['timezone']);
        $this->assertSame('Acme Corp', $settings['unsubscribe']['brand']);
    }
}
