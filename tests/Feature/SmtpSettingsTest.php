<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Mail\MailManager;
use App\Mail\Providers\ResendProvider;
use App\Mail\Providers\SmtpProvider;
use App\Models\Domain;
use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\ProviderConfig;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SmtpSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const ORG_PASSWORD = 'org-smtp-S3cret!pass';

    private const PLATFORM_PASSWORD = 'platform-smtp-Pa55word';

    private function workspace(string $role = 'owner', ?MailProvider $provider = null): array
    {
        $user = User::factory()->create();
        $provider ??= MailProvider::factory()->create(['key' => 'resend_'.uniqid(), 'driver' => 'resend', 'type' => 'api', 'status' => 'active']);
        $org = Organization::factory()->create([
            'mail_provider_id' => $provider->id,
            'default_provider' => $provider->driver,
        ]);
        $org->users()->attach($user->id, ['role' => $role]);

        return [$user, $org];
    }

    private function as(User $user, Organization $org): static
    {
        return $this->actingAs($user)->withSession(['current_organization_id' => $org->id]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'host' => 'smtp.mailhost.test',
            'port' => 587,
            'username' => 'apikey',
            'password' => self::ORG_PASSWORD,
            'encryption' => 'tls',
        ], $overrides);
    }

    private function smtpConfig(Organization $org): ?ProviderConfig
    {
        return ProviderConfig::query()->where('organization_id', $org->id)->where('provider', 'smtp')->first();
    }

    public function test_owner_can_save_smtp_settings(): void
    {
        [$user, $org] = $this->workspace();

        $this->as($user, $org)
            ->put(route('settings.smtp.update'), $this->validPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $config = $this->smtpConfig($org);
        $this->assertNotNull($config);
        $this->assertTrue($config->is_active);
        $this->assertSame('smtp.mailhost.test', $config->credentials['host']);
        $this->assertSame(587, $config->credentials['port']);
        $this->assertSame('apikey', $config->credentials['username']);
        $this->assertSame('tls', $config->credentials['encryption']);
        $this->assertSame(self::ORG_PASSWORD, $config->credentials['password']);
    }

    public function test_workspace_admin_can_save_but_member_cannot(): void
    {
        [$admin, $org] = $this->workspace('admin');
        $this->as($admin, $org)->put(route('settings.smtp.update'), $this->validPayload())->assertRedirect();
        $this->assertNotNull($this->smtpConfig($org));

        [$member, $other] = $this->workspace('member');
        $this->as($member, $other)->put(route('settings.smtp.update'), $this->validPayload())->assertForbidden();
        $this->assertNull($this->smtpConfig($other));

        // Members cannot access settings pages - they require 'manage' ability
        $this->as($member, $other)
            ->get(route('settings', 'smtp'))
            ->assertForbidden();
    }

    public function test_password_is_encrypted_at_rest(): void
    {
        [$user, $org] = $this->workspace();
        $this->as($user, $org)->put(route('settings.smtp.update'), $this->validPayload());

        $raw = (string) DB::table('provider_configs')->where('organization_id', $org->id)->value('credentials');

        $this->assertStringNotContainsString(self::ORG_PASSWORD, $raw);
        $this->assertStringNotContainsString('smtp.mailhost.test', $raw);
        $this->assertSame(self::ORG_PASSWORD, json_decode(Crypt::decryptString($raw), true)['password']);
    }

    public function test_blank_password_keeps_existing_and_clear_removes_it(): void
    {
        [$user, $org] = $this->workspace();
        $this->as($user, $org)->put(route('settings.smtp.update'), $this->validPayload());

        $this->as($user, $org)
            ->put(route('settings.smtp.update'), $this->validPayload(['password' => '', 'host' => 'smtp2.mailhost.test']))
            ->assertSessionHasNoErrors();

        $config = $this->smtpConfig($org);
        $this->assertSame('smtp2.mailhost.test', $config->credentials['host']);
        $this->assertSame(self::ORG_PASSWORD, $config->credentials['password']);

        $this->as($user, $org)
            ->put(route('settings.smtp.update'), $this->validPayload(['password' => null, 'clear_password' => true]))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->smtpConfig($org)->credentials['password']);
    }

    public function test_validation_errors(): void
    {
        [$user, $org] = $this->workspace();

        $this->as($user, $org)
            ->put(route('settings.smtp.update'), $this->validPayload(['host' => '', 'port' => 70000, 'encryption' => 'starttls']))
            ->assertSessionHasErrors(['host', 'port', 'encryption']);

        $this->as($user, $org)
            ->put(route('settings.smtp.update'), $this->validPayload(['host' => 'smtp://evil/x', 'port' => 'abc']))
            ->assertSessionHasErrors(['host', 'port']);

        $this->assertNull($this->smtpConfig($org));

        // Host is optional while SMTP is off.
        $this->as($user, $org)
            ->put(route('settings.smtp.update'), ['enabled' => false, 'host' => '', 'port' => null, 'encryption' => 'none'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($this->smtpConfig($org)->is_active);
    }

    public function test_settings_page_exposes_only_has_password(): void
    {
        [$user, $org] = $this->workspace();
        $this->as($user, $org)->put(route('settings.smtp.update'), $this->validPayload());

        $this->as($user, $org)
            ->get(route('settings', 'smtp'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Index')
                ->where('smtp.enabled', true)
                ->where('smtp.host', 'smtp.mailhost.test')
                ->where('smtp.port', 587)
                ->where('smtp.username', 'apikey')
                ->where('smtp.encryption', 'tls')
                ->where('smtp.has_password', true)
                ->where('smtp.can_manage', true)
                ->where('smtp.sending_via', 'smtp')
                ->missing('smtp.password'));
    }

    public function test_smtp_passwords_never_reach_the_browser(): void
    {
        $platform = MailProvider::factory()->create([
            'key' => 'smtp_platform',
            'driver' => 'smtp',
            'type' => 'smtp',
            'status' => 'active',
            'config' => [
                ['key' => 'HOST', 'value' => 'relay.platform.test', 'secret' => false],
                ['key' => 'USERNAME', 'value' => 'platform-user', 'secret' => false],
                ['key' => 'PASSWORD', 'value' => self::PLATFORM_PASSWORD, 'secret' => true],
            ],
        ]);
        [$user, $org] = $this->workspace('owner', $platform);
        $this->as($user, $org)->put(route('settings.smtp.update'), $this->validPayload());

        foreach (['settings/smtp', 'emails', 'inbox', 'domains'] as $path) {
            $html = $this->as($user, $org)->get('/'.$path)->assertOk()->getContent();
            $this->assertStringNotContainsString(self::PLATFORM_PASSWORD, $html, $path);
            $this->assertStringNotContainsString(self::ORG_PASSWORD, $html, $path);

            $json = $this->as($user, $org)
                ->withHeaders([
                    'X-Inertia' => 'true',
                    'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                    'X-Requested-With' => 'XMLHttpRequest',
                ])
                ->get('/'.$path)
                ->assertOk()
                ->assertHeader('X-Inertia', 'true')
                ->getContent();
            $this->assertStringNotContainsString(self::PLATFORM_PASSWORD, $json, $path);
            $this->assertStringNotContainsString(self::ORG_PASSWORD, $json, $path);
            $this->flushHeaders();
        }

        $this->as($user, $org)
            ->get(route('settings', 'smtp'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tenant.smtp.has_password', true)
                ->where('tenant.smtp.host', 'relay.platform.test')
                ->missing('tenant.smtp.password')
                ->missing('tenant.smtp.username')
                ->missing('tenant.provider.config'));
    }

    public function test_admin_provider_list_masks_secrets_and_blank_keeps_them(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $provider = MailProvider::factory()->create([
            'key' => 'smtp_admin',
            'driver' => 'smtp',
            'type' => 'smtp',
            'config' => [
                ['key' => 'HOST', 'value' => 'relay.platform.test', 'secret' => false],
                ['key' => 'PASSWORD', 'value' => self::PLATFORM_PASSWORD, 'secret' => true],
            ],
        ]);

        $content = $this->actingAs($admin)->get(route('admin.providers'))->assertOk()->getContent();
        $this->assertStringNotContainsString(self::PLATFORM_PASSWORD, $content);
        $this->assertStringContainsString('relay.platform.test', $content);

        $this->actingAs($admin)->put(route('admin.providers.update', $provider), [
            'name' => $provider->name,
            'status' => 'active',
            'config' => [
                ['key' => 'HOST', 'value' => 'relay2.platform.test', 'secret' => false],
                ['key' => 'PASSWORD', 'value' => '', 'secret' => true],
            ],
        ])->assertRedirect();

        $config = collect($provider->fresh()->config)->keyBy('key');
        $this->assertSame('relay2.platform.test', $config['HOST']['value']);
        $this->assertSame(self::PLATFORM_PASSWORD, $config['PASSWORD']['value']);
    }

    public function test_mail_manager_picks_smtp_for_workspace_with_enabled_config(): void
    {
        config(['maildesk.fake_send' => false]);
        config(['maildesk.providers.smtp' => ['driver' => 'smtp', 'host' => 'env.smtp.test', 'port' => 2525, 'username' => 'env-user', 'password' => 'env-pass', 'encryption' => 'tls']]);

        [$user, $org] = $this->workspace();
        $manager = app(MailManager::class);

        $this->assertInstanceOf(ResendProvider::class, $manager->forOrganization($org));
        $this->assertSame('resend', $manager->driverNameFor($org));

        $this->as($user, $org)->put(route('settings.smtp.update'), $this->validPayload(['port' => 465, 'encryption' => 'ssl']));
        $org->refresh();

        $this->assertInstanceOf(SmtpProvider::class, $manager->forOrganization($org));
        $this->assertSame('smtp', $manager->driverNameFor($org));

        $resolved = $manager->smtpConfigFor($org);
        $this->assertSame('smtp.mailhost.test', $resolved['host']);
        $this->assertSame(465, $resolved['port']);
        $this->assertSame('ssl', $resolved['encryption']);
        $this->assertSame('apikey', $resolved['username']);
        $this->assertSame(self::ORG_PASSWORD, $resolved['password']);

        // Turning it off falls back to the platform provider (Resend) again.
        $this->as($user, $org)->put(route('settings.smtp.update'), $this->validPayload(['enabled' => false, 'password' => '']));
        $this->assertInstanceOf(ResendProvider::class, $manager->forOrganization($org->refresh()));
    }

    public function test_platform_smtp_provider_layers_admin_config_over_env(): void
    {
        config(['maildesk.fake_send' => false]);
        config(['maildesk.providers.smtp' => ['driver' => 'smtp', 'host' => 'env.smtp.test', 'port' => 2525, 'username' => null, 'password' => null, 'encryption' => 'tls']]);

        $platform = MailProvider::factory()->create([
            'key' => 'smtp_layer', 'driver' => 'smtp', 'type' => 'smtp', 'status' => 'active',
            'config' => [
                ['key' => 'HOST', 'value' => 'relay.platform.test', 'secret' => false],
                ['key' => 'PASSWORD', 'value' => self::PLATFORM_PASSWORD, 'secret' => true],
            ],
        ]);
        [, $org] = $this->workspace('owner', $platform);
        $manager = app(MailManager::class);

        $this->assertInstanceOf(SmtpProvider::class, $manager->forOrganization($org));
        $resolved = $manager->smtpConfigFor($org);
        $this->assertSame('relay.platform.test', $resolved['host']);
        $this->assertSame(2525, $resolved['port']);
        $this->assertSame(self::PLATFORM_PASSWORD, $resolved['password']);

        // A workspace's own server never inherits the platform credentials.
        ProviderConfig::query()->create([
            'organization_id' => $org->id,
            'provider' => 'smtp',
            'credentials' => ['host' => 'own.smtp.test', 'port' => 587, 'encryption' => 'tls'],
            'is_active' => true,
        ]);
        $own = $manager->smtpConfigFor($org->refresh());
        $this->assertSame('own.smtp.test', $own['host']);
        $this->assertNull($own['password']);
        $this->assertNull($own['username']);
    }

    public function test_sent_message_records_smtp_provider_under_fake_send(): void
    {
        [$user, $org] = $this->workspace();
        Domain::factory()->verified()->create(['organization_id' => $org->id, 'name' => 'acme.test']);
        $this->as($user, $org)->put(route('settings.smtp.update'), $this->validPayload());

        $message = app(EmailService::class)->send($org->refresh(), [
            'from' => 'hello@acme.test',
            'to' => ['sam@example.com'],
            'subject' => 'Hi',
            'text' => 'Hello',
        ]);

        $this->assertSame('sent', $message->status);
        $this->assertSame('smtp', $message->provider);
    }
}
