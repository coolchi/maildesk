<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ErrorPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    protected function signedIn(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $org];
    }

    public function test_missing_page_renders_branded_inertia_404(): void
    {
        [$user, $org] = $this->signedIn();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Error')
                ->where('status', 404)
                ->where('title', 'Page not found')
                ->where('message', 'That page could not be found.'));
    }

    public function test_method_not_allowed_renders_branded_inertia_405(): void
    {
        [$user, $org] = $this->signedIn();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('settings.mail-clients.enable', 1))
            ->assertStatus(405)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Error')
                ->where('status', 405)
                ->where('title', 'Method not allowed')
                ->where('message', 'That action is not available for this page. Go back and try again.'));
    }

    public function test_guest_html_404_does_not_use_symfony_default_page(): void
    {
        $response = $this->get('/this-page-does-not-exist');

        $response->assertNotFound();
        $this->assertStringContainsString('Page not found', $response->getContent());
        $this->assertStringNotContainsString('Oops! An Error Occurred', $response->getContent());
    }

    public function test_non_html_404_uses_blade_error_view(): void
    {
        $response = $this->withHeaders(['Accept' => 'text/plain'])
            ->get('/this-page-does-not-exist');

        $response->assertNotFound();
        $this->assertStringContainsString('Page not found', $response->getContent());
        $this->assertStringContainsString('Log in', $response->getContent());
        $this->assertStringNotContainsString('Oops! An Error Occurred', $response->getContent());
    }

    public function test_json_404_stays_json(): void
    {
        $this->getJson('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    public function test_expired_page_redirects_back_with_flash(): void
    {
        [$user, $org] = $this->signedIn();

        Route::middleware('web')->get('/__test/419', function () {
            abort(419);
        });

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->from(route('settings', 'mail-clients'))
            ->get('/__test/419')
            ->assertRedirect(route('settings', 'mail-clients'))
            ->assertSessionHas('error', 'This page expired. Refresh and try again.');
    }

    public function test_json_expired_page_stays_json(): void
    {
        Route::middleware('web')->get('/__test/419-json', function () {
            abort(419);
        });

        $this->getJson('/__test/419-json')
            ->assertStatus(419)
            ->assertJson(['message' => 'This page expired. Refresh and try again.']);
    }

    public function test_too_many_requests_renders_branded_page_when_aborted(): void
    {
        [$user, $org] = $this->signedIn();

        Route::middleware('web')->get('/__test/429', function () {
            abort(429);
        });

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get('/__test/429')
            ->assertStatus(429)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Error')
                ->where('status', 429)
                ->where('title', 'Too many requests'));
    }

    public function test_server_error_is_branded_when_debug_is_off(): void
    {
        config(['app.debug' => false]);

        Route::middleware('web')->get('/__test/500-prod', function () {
            abort(500, 'secret-debug-detail');
        });

        [$user, $org] = $this->signedIn();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get('/__test/500-prod')
            ->assertStatus(500)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Error')
                ->where('status', 500)
                ->where('title', 'Something went wrong')
                ->where('message', 'Something went wrong. Try again in a moment.'));
    }
}
