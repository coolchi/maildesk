<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InboxSoundPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_sound_defaults_to_enabled_in_shared_auth(): void
    {
        $user = User::factory()->create(['preferences' => null]);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.preferences.inbox_sound', true));

        $this->assertTrue($user->prefersInboxSound());
    }

    public function test_user_can_disable_and_reenable_inbox_sound(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.preferences'), ['inbox_sound' => false])
            ->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertFalse($user->prefersInboxSound());
        $this->assertFalse($user->preferences['inbox_sound']);

        $this->actingAs($user)
            ->patch(route('profile.preferences'), ['inbox_sound' => true])
            ->assertRedirect(route('profile.edit'));

        $this->assertTrue($user->fresh()->prefersInboxSound());
    }
}
