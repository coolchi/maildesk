<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HelpPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_sees_help_page(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'member']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('help'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Help/Index')
                ->has('appName')
                ->where('paymentsConfigured', false));
    }

    public function test_help_page_never_exposes_payment_secrets(): void
    {
        config(['services.monipay.public_key' => 'pub_test_help', 'services.monipay.secret_key' => 'pri_test_help_secret_value']);
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $html = $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get('/help')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('paymentsConfigured', true))
            ->getContent();

        $this->assertStringNotContainsString('pri_test_help_secret_value', $html);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/help')->assertRedirect(route('login'));
    }
}
