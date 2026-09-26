<?php

namespace Tests\Feature;

use App\Models\MailProvider;
use App\Models\User;
use App\Services\Providers\SmtpConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AdminProviderConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const RESEND_KEY = 're_live_SuperSecretKey_9f2a1c81e';

    private const SMTP_PASSWORD = 'smtp-Pa55word-never-leak';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['maildesk.providers.smtp' => ['driver' => 'smtp', 'port' => 587, 'encryption' => 'tls']]);
    }

    private function resend(): MailProvider
    {
        return MailProvider::factory()->create([
            'driver' => 'resend', 'type' => 'api', 'key' => 'resend_t',
            'config' => [['key' => 'API_KEY', 'value' => self::RESEND_KEY, 'secret' => true]],
        ]);
    }

    private function smtp(): MailProvider
    {
        return MailProvider::factory()->create([
            'driver' => 'smtp', 'type' => 'smtp', 'key' => 'smtp_t',
            'config' => [
                ['key' => 'HOST', 'value' => 'smtp.example.test', 'secret' => false],
                ['key' => 'PORT', 'value' => '587', 'secret' => false],
                ['key' => 'USERNAME', 'value' => 'mailer', 'secret' => false],
                ['key' => 'PASSWORD', 'value' => self::SMTP_PASSWORD, 'secret' => true],
                ['key' => 'ENCRYPTION', 'value' => 'tls', 'secret' => false],
            ],
        ]);
    }

    private function fakeSmtp(?\Throwable $error = null): object
    {
        $fake = new class($error) extends SmtpConnector
        {
            public array $calls = [];

            public function __construct(private ?\Throwable $error) {}

            public function check(array $config, float $timeoutSeconds = 10.0): void
            {
                $this->calls[] = [$config, $timeoutSeconds];
                if ($this->error) {
                    throw $this->error;
                }
            }
        };
        $this->app->instance(SmtpConnector::class, $fake);

        return $fake;
    }

    private function test(MailProvider $provider)
    {
        return $this->actingAs(User::factory()->platformAdmin()->create())
            ->postJson(route('admin.providers.test', $provider));
    }

    private function assertNoSecret(string $content): void
    {
        $this->assertStringNotContainsString(self::RESEND_KEY, $content);
        $this->assertStringNotContainsString(self::SMTP_PASSWORD, $content);
    }

    public function test_resend_success(): void
    {
        Http::fake(['api.resend.com/domains' => Http::response(['data' => []], 200)]);

        $response = $this->test($this->resend())->assertOk()
            ->assertJson(['ok' => true, 'driver' => 'resend'])
            ->assertJsonStructure(['ok', 'message', 'latency_ms', 'driver']);

        $this->assertSame(['ok', 'message', 'latency_ms', 'driver'], array_keys($response->json()));
        $this->assertIsInt($response->json('latency_ms'));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET'
            && $r->url() === 'https://api.resend.com/domains'
            && $r->hasHeader('Authorization', 'Bearer '.self::RESEND_KEY));
        $this->assertNoSecret($response->getContent());
    }

    public function test_resend_bad_key_401(): void
    {
        Http::fake(['api.resend.com/domains' => Http::response(['message' => 'API key is invalid: '.self::RESEND_KEY], 401)]);

        $response = $this->test($this->resend())->assertOk()->assertJson(['ok' => false]);
        $this->assertStringContainsString('rejected the API key', $response->json('message'));
        $this->assertNoSecret($response->getContent());
    }

    public function test_resend_unreachable_or_timeout(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out with key '.self::RESEND_KEY));

        $response = $this->test($this->resend())->assertOk()->assertJson(['ok' => false]);
        $this->assertStringContainsString('Could not reach Resend', $response->json('message'));
        $this->assertStringContainsString('[redacted]', $response->json('message'));
        $this->assertNoSecret($response->getContent());
    }

    public function test_smtp_success_uses_stored_credentials_and_short_timeout(): void
    {
        $fake = $this->fakeSmtp();

        $response = $this->test($this->smtp())->assertOk()->assertJson(['ok' => true, 'driver' => 'smtp']);

        [$config, $timeout] = $fake->calls[0];
        $this->assertSame('smtp.example.test', $config['host']);
        $this->assertSame('mailer', $config['username']);
        $this->assertSame(self::SMTP_PASSWORD, $config['password']);
        $this->assertSame(10.0, $timeout);
        $this->assertStringContainsString('no message was sent', $response->json('message'));
        $this->assertNoSecret($response->getContent());
    }

    public function test_smtp_unreachable_host_and_auth_failure_are_scrubbed(): void
    {
        $provider = $this->smtp();
        $this->fakeSmtp(new RuntimeException('Connection could not be established with host "ssl://smtp.example.test:587": timed out'));
        $this->test($provider)->assertOk()->assertJson(['ok' => false]);

        $this->fakeSmtp(new RuntimeException('Failed to authenticate on SMTP server with username "mailer" using password '.self::SMTP_PASSWORD.' ('.base64_encode(self::SMTP_PASSWORD).')'));
        $response = $this->test($provider)->assertOk()->assertJson(['ok' => false]);
        $this->assertStringContainsString('Failed to authenticate', $response->json('message'));
        $this->assertNoSecret($response->getContent());
        $this->assertStringNotContainsString(base64_encode(self::SMTP_PASSWORD), $response->getContent());
    }

    public function test_unsupported_driver_is_reported_honestly(): void
    {
        $provider = MailProvider::factory()->create(['driver' => 'postmark', 'type' => 'api', 'key' => 'pm_t']);

        $this->test($provider)->assertOk()->assertJson([
            'ok' => false, 'driver' => 'postmark', 'latency_ms' => null,
        ])->assertJsonPath('message', 'Test connection is not supported for the "postmark" driver yet.');
        Http::assertNothingSent();
    }

    public function test_non_admin_gets_403(): void
    {
        $provider = $this->resend();

        $this->actingAs(User::factory()->create())->postJson(route('admin.providers.test', $provider))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_providers_page_never_contains_secrets(): void
    {
        $this->resend();
        $this->smtp();

        $content = $this->actingAs(User::factory()->platformAdmin()->create())->get(route('admin.providers'))->assertOk()->getContent();
        $this->assertNoSecret($content);
    }

    public function test_nothing_is_stored(): void
    {
        Http::fake(['api.resend.com/domains' => Http::response([], 200)]);
        $provider = $this->resend();
        $before = $provider->fresh()->getAttributes();

        $this->test($provider)->assertOk();

        $this->assertSame($before, $provider->fresh()->getAttributes());
    }
}
