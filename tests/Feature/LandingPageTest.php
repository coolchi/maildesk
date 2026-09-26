<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_the_public_landing_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Welcome')
                ->where('canLogin', true)
                ->where('canRegister', true)
                ->where('auth.user', null));
    }

    public function test_landing_ctas_point_at_real_routes(): void
    {
        // The page links its primary CTA to registration and its secondary
        // header link to login; both must resolve for guests.
        $this->assertSame('/register', route('register', absolute: false));
        $this->assertSame('/login', route('login', absolute: false));

        $this->get(route('register'))->assertOk();
        $this->get(route('login'))->assertOk();
    }

    public function test_landing_component_wires_cta_and_features(): void
    {
        $vue = file_get_contents(resource_path('js/Pages/Welcome.vue'));

        $this->assertStringContainsString('data-testid="landing-primary-cta"', $vue);
        $this->assertStringContainsString("route('register')", $vue);
        $this->assertStringContainsString('/api/v1/emails', $vue);
        // No invented pricing on the public page.
        $this->assertDoesNotMatchRegularExpression('/[$₦]\s?\d/u', $vue);
    }

    public function test_signed_in_user_still_gets_the_landing_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Welcome')
                ->where('auth.user.id', $user->id));
    }
}
