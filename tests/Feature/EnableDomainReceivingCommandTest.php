<?php

namespace Tests\Feature;

use App\Models\DnsConnection;
use App\Models\Domain;
use App\Models\Organization;
use App\Services\Domains\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnableDomainReceivingCommandTest extends TestCase
{
    use RefreshDatabase;

    private const CF = 'https://api.cloudflare.com/client/v4';

    private const TOKEN = 'cf-test-token-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(DnsResolver::class, new FakeDnsResolverForCommand);
        config(['maildesk.providers.resend.api_key' => null]);
        Http::preventStrayRequests();
    }

    private function setUpVerifiedDomain(): Domain
    {
        $org = Organization::factory()->create(['region' => 'eu-west-1']);

        return Domain::factory()->verified()->create([
            'organization_id' => $org->id,
            'name' => 'recv.test',
            'provider' => 'resend',
            'provider_domain_id' => 'dom_cmd',
            'dns_records' => [
                'records' => [
                    ['key' => 'dkim', 'type' => 'TXT', 'name' => 'resend._domainkey', 'value' => 'p=KEY', 'label' => 'DKIM'],
                    ['key' => 'inbound_mx', 'type' => 'MX', 'name' => '@', 'value' => 'inbound-smtp.eu-west-1.amazonaws.com', 'priority' => 10, 'label' => 'MX (receiving)'],
                ],
                'checks' => ['dkim' => true, 'inbound_mx' => false],
            ],
        ]);
    }

    private function connect(Domain $domain): DnsConnection
    {
        return DnsConnection::create([
            'organization_id' => $domain->organization_id,
            'domain_id' => $domain->id,
            'provider' => 'cloudflare',
            'credentials' => ['api_token' => self::TOKEN],
            'zone_id' => 'zone1',
            'zone_name' => 'recv.test',
        ]);
    }

    private function fakeCloudflare(array $live = []): void
    {
        Http::fake([
            self::CF.'/zones?*' => Http::response(['success' => true, 'result' => [['id' => 'zone1', 'name' => 'recv.test']]]),
            self::CF.'/zones/zone1/dns_records?*' => Http::response(['success' => true, 'result' => $live]),
            self::CF.'/zones/zone1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'new']]),
            self::CF.'/zones/zone1/dns_records/*' => Http::response(['success' => true, 'result' => ['id' => 'x']]),
        ]);
    }

    public function test_command_requires_domain_or_all_flag(): void
    {
        $this->artisan('domains:enable-receiving')
            ->assertExitCode(1)
            ->expectsOutput('Please specify --domain=<name> or --all to process multiple domains.');
    }

    public function test_command_enables_receiving_for_specific_domain(): void
    {
        $domain = $this->setUpVerifiedDomain();
        $this->connect($domain);
        $this->fakeCloudflare([]);

        $this->artisan('domains:enable-receiving', ['--domain' => 'recv.test'])
            ->assertExitCode(0)
            ->expectsOutput('recv.test: Receiving MX record published.');

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && $r['type'] === 'MX'
            && $r['name'] === 'recv.test'
            && str_contains($r['content'], 'inbound-smtp'));
    }

    public function test_command_dry_run_does_not_publish(): void
    {
        $domain = $this->setUpVerifiedDomain();
        $this->connect($domain);
        $this->fakeCloudflare([]);

        $this->artisan('domains:enable-receiving', ['--domain' => 'recv.test', '--dry-run' => true])
            ->assertExitCode(0)
            ->expectsOutput('recv.test: WOULD ADD MX record inbound-smtp.eu-west-1.amazonaws.com (priority 10)')
            ->expectsOutput('This was a dry run. No changes were made.');

        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['type'] === 'MX');
    }

    public function test_command_skips_domain_with_existing_mx_without_force(): void
    {
        $domain = $this->setUpVerifiedDomain();
        $this->connect($domain);
        $this->fakeCloudflare([
            ['id' => 'mx1', 'type' => 'MX', 'name' => 'recv.test', 'content' => 'mx.zoho.com', 'priority' => 10],
        ]);

        $this->artisan('domains:enable-receiving', ['--domain' => 'recv.test'])
            ->assertExitCode(0)
            ->expectsOutput('recv.test: Has existing MX records (mx.zoho.com). Use --force to override.');

        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['type'] === 'MX');
    }

    public function test_command_force_publishes_with_existing_mx(): void
    {
        $domain = $this->setUpVerifiedDomain();
        $this->connect($domain);
        $this->fakeCloudflare([
            ['id' => 'mx1', 'type' => 'MX', 'name' => 'recv.test', 'content' => 'mx.zoho.com', 'priority' => 10],
        ]);

        $this->artisan('domains:enable-receiving', ['--domain' => 'recv.test', '--force' => true])
            ->assertExitCode(0)
            ->expectsOutput('recv.test: Receiving MX record published.');

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['type'] === 'MX');
    }

    public function test_command_shows_manual_record_for_domains_without_connection(): void
    {
        $domain = $this->setUpVerifiedDomain();
        Http::fake();

        $this->artisan('domains:enable-receiving', ['--domain' => 'recv.test'])
            ->assertExitCode(0)
            ->expectsOutput('recv.test: No DNS provider connected. Receiving MX record: @ -> inbound-smtp.eu-west-1.amazonaws.com (priority 10)');
    }

    public function test_command_warns_for_domain_without_inbound_mx(): void
    {
        $org = Organization::factory()->create();
        Domain::factory()->verified()->create([
            'organization_id' => $org->id,
            'name' => 'noinbound.test',
            'dns_records' => [
                'records' => [['key' => 'dkim', 'type' => 'TXT', 'name' => 'resend._domainkey', 'value' => 'p=KEY']],
                'checks' => ['dkim' => true],
            ],
        ]);
        Http::fake();

        $this->artisan('domains:enable-receiving', ['--domain' => 'noinbound.test'])
            ->assertExitCode(0)
            ->expectsOutputToContain('No inbound_mx record configured');
    }

    public function test_command_all_without_force_skips_domains_with_existing_mx(): void
    {
        $domain = $this->setUpVerifiedDomain();
        $this->connect($domain);
        $this->fakeCloudflare([
            ['id' => 'mx1', 'type' => 'MX', 'name' => 'recv.test', 'content' => 'mx.zoho.com', 'priority' => 10],
        ]);

        $this->artisan('domains:enable-receiving', ['--all' => true])
            ->assertExitCode(0)
            ->expectsOutput('recv.test: Has existing MX records (mx.zoho.com). Use --force to override.');

        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['type'] === 'MX');
    }
}

class FakeDnsResolverForCommand extends DnsResolver
{
    public array $txt = [];

    public array $mx = [];

    public array $cname = [];

    public function txt(string $host): array
    {
        return $this->txt[strtolower($host)] ?? [];
    }

    public function mx(string $host): array
    {
        return $this->mx[strtolower($host)] ?? [];
    }

    public function cname(string $host): array
    {
        return $this->cname[strtolower($host)] ?? [];
    }
}
