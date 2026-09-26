<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class LogoutCurrentDeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_signs_out_only_the_current_device(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['remember_token' => 'other-devices-remember-token'])->save();

        $recaller = Auth::guard('web')->getRecallerName();
        $cookieValue = $user->id.'|other-devices-remember-token|'.$user->getAuthPassword();

        $response = $this->actingAs($user)
            ->withCookie($recaller, $cookieValue)
            ->post(route('logout'));

        $response->assertRedirect('/');
        $this->assertGuest('web');

        // Other remembered devices keep working: the token is not cycled.
        $this->assertSame('other-devices-remember-token', $user->fresh()->remember_token);

        // This browser's remember cookie is expired.
        $response->assertCookieExpired($recaller);
    }

    public function test_logout_without_remember_cookie_still_logs_out_and_keeps_token(): void
    {
        $user = User::factory()->create();
        $token = $user->remember_token;

        $this->actingAs($user)->post(route('logout'))->assertRedirect('/');

        $this->assertGuest('web');
        $this->assertSame($token, $user->fresh()->remember_token);
    }

    public function test_logout_rotates_csrf_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
        $before = session()->token();

        $this->post(route('logout'));

        $this->assertNotSame($before, session()->token());
    }
}
