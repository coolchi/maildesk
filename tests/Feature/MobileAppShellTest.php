<?php

namespace Tests\Feature;

use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileAppShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_document_enables_safe_area_and_standalone_web_app_meta(): void
    {
        $user = User::factory()->create();
        $provider = MailProvider::factory()->create([
            'status' => 'active',
            'driver' => 'resend',
        ]);
        $org = Organization::factory()->create([
            'default_provider' => 'resend',
            'mail_provider_id' => $provider->id,
            'status' => 'active',
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('inbox'))
            ->assertOk()
            ->assertSee('viewport-fit=cover', false)
            ->assertSee('apple-mobile-web-app-capable', false)
            ->assertSee('mobile-web-app-capable', false)
            ->assertSee('name="theme-color"', false);
    }
}
