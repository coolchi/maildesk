<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSignupOpen;
use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\User;
use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminPlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->platformAdmin()->create();
    }

    /** @return array<string, bool> */
    private function defaultAiFeatures(bool $enabled = false): array
    {
        return collect(array_keys(PlatformSettings::AI_FEATURES))
            ->mapWithKeys(fn (string $key) => [$key => $enabled])
            ->all();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'signup_open' => true,
            'platform_name' => '',
            'support_email' => '',
            'default_workspace_provider_id' => null,
            'trial_days' => 14,
            'ai_enabled' => false,
            'ai_features' => $this->defaultAiFeatures(),
            'ai_provider' => 'openai',
            'ai_model' => '',
            'ai_api_key' => '',
        ], $overrides);
    }

    public function test_non_admin_cannot_view_or_update_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.settings'))->assertForbidden();
        $this->actingAs($user)->put(route('admin.settings.update'), $this->payload(['signup_open' => false]))->assertForbidden();
        $this->assertTrue(app(PlatformSettings::class)->signupOpen());
    }

    public function test_admin_sees_settings_page_with_defaults(): void
    {
        $this->actingAs($this->admin())->get(route('admin.settings'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/Index')
                ->where('settings.signup_open', true)
                ->where('settings.default_workspace_provider_id', null)
                ->where('settings.ai.enabled', false)
                ->where('settings.ai.features.smart_triage.enabled', false)
                ->where('settings.ai.features.smart_triage.label', 'Smart triage'));
    }

    public function test_closing_signups_blocks_register_routes_and_hides_the_button(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.settings.update'), $this->payload(['signup_open' => false]))
            ->assertSessionHasNoErrors();
        auth()->logout();

        $this->get('/register')->assertRedirect(route('login'));
        $this->post('/register', [
            'name' => 'X', 'email' => 'x@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertRedirect(route('login'))->assertSessionHasErrors(['email' => EnsureSignupOpen::MESSAGE]);
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('canRegister', false));
    }

    public function test_reopening_signups_allows_registration(): void
    {
        app(PlatformSettings::class)->set(['signup_open' => false]);
        app(PlatformSettings::class)->set(['signup_open' => true]);

        $this->get('/register')->assertOk();
    }

    public function test_platform_name_and_support_email_override_config(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.settings.update'), $this->payload([
                'platform_name' => 'Acme Mail',
                'support_email' => 'help@acme.test',
            ]))->assertSessionHasNoErrors();

        $this->assertSame('Acme Mail', config('app.name'));
        $this->assertSame('help@acme.test', config('maildesk.support_email'));
    }

    public function test_invalid_values_are_rejected(): void
    {
        $disabled = MailProvider::factory()->create(['key' => 'off', 'status' => 'disabled']);

        $this->actingAs($this->admin())
            ->put(route('admin.settings.update'), $this->payload([
                'support_email' => 'not-an-email',
                'default_workspace_provider_id' => $disabled->id,
            ]))->assertSessionHasErrors(['support_email', 'default_workspace_provider_id']);

        $this->assertNull(app(PlatformSettings::class)->defaultWorkspaceProviderId());
    }

    public function test_new_workspaces_get_the_configured_provider(): void
    {
        $provider = MailProvider::factory()->create(['key' => 'smtp_new', 'driver' => 'smtp', 'status' => 'active']);

        $this->actingAs($this->admin())
            ->put(route('admin.settings.update'), $this->payload(['default_workspace_provider_id' => $provider->id]))
            ->assertSessionHasNoErrors();

        $org = Organization::factory()->create(['mail_provider_id' => null]);
        $this->assertSame($provider->id, $org->mail_provider_id);
        $this->assertSame('smtp', $org->default_provider);

        // An explicit assignment is never overridden.
        $other = MailProvider::factory()->create(['key' => 'other', 'driver' => 'resend']);
        $explicit = Organization::factory()->create(['mail_provider_id' => $other->id]);
        $this->assertSame($other->id, $explicit->mail_provider_id);

        // A provider disabled after being chosen is ignored.
        $provider->update(['status' => 'disabled']);
        $later = Organization::factory()->create(['mail_provider_id' => null]);
        $this->assertNull($later->mail_provider_id);
    }

    public function test_admin_can_enable_selected_ai_features(): void
    {
        $features = $this->defaultAiFeatures();
        $features['smart_triage'] = true;
        $features['reply_draft'] = true;

        $this->actingAs($this->admin())
            ->put(route('admin.settings.update'), $this->payload([
                'ai_enabled' => true,
                'ai_features' => $features,
            ]))
            ->assertSessionHasNoErrors();

        $settings = app(PlatformSettings::class);

        $this->assertTrue($settings->aiEnabled());
        $this->assertTrue($settings->aiFeatureEnabled('smart_triage'));
        $this->assertTrue($settings->aiFeatureEnabled('reply_draft'));
        $this->assertFalse($settings->aiFeatureEnabled('thread_summary'));
        $this->assertFalse($settings->aiFeatureEnabled('compose_assist'));
    }

    public function test_ai_features_are_off_when_master_switch_is_disabled(): void
    {
        $features = $this->defaultAiFeatures(true);

        $this->actingAs($this->admin())
            ->put(route('admin.settings.update'), $this->payload([
                'ai_enabled' => false,
                'ai_features' => $features,
            ]))
            ->assertSessionHasNoErrors();

        $settings = app(PlatformSettings::class);

        $this->assertFalse($settings->aiEnabled());
        $this->assertFalse($settings->aiFeatureEnabled('smart_triage'));
        $this->assertTrue($settings->aiSettings()['features']['smart_triage']['enabled']);
    }

    public function test_unknown_ai_feature_is_rejected(): void
    {
        $features = $this->defaultAiFeatures();
        $features['not_a_real_feature'] = true;

        $this->actingAs($this->admin())
            ->put(route('admin.settings.update'), $this->payload([
                'ai_enabled' => true,
                'ai_features' => $features,
            ]))
            ->assertSessionHasErrors(['ai_features']);

        $this->assertFalse(app(PlatformSettings::class)->aiEnabled());
    }

    public function test_missing_ai_feature_toggle_is_rejected(): void
    {
        $features = $this->defaultAiFeatures();
        unset($features['smart_triage']);

        $this->actingAs($this->admin())
            ->put(route('admin.settings.update'), $this->payload([
                'ai_enabled' => true,
                'ai_features' => $features,
            ]))
            ->assertSessionHasErrors(['ai_features']);
    }
}
