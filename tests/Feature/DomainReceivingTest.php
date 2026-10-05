<?php

namespace Tests\Feature;

use App\Models\DnsConnection;
use App\Models\Domain;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dns\DnsRecordManager;
use App\Services\Domains\DnsResolver;
use App\Services\Domains\DomainVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DomainReceivingTest extends TestCase
{
    use RefreshDatabase;

    private const CF = 'https://api.cloudflare.com/client/v4';

    private const RESEND = 'https://api.resend.com';

    private const TOKEN = 'cf-test-token-0123456789abcdef';

    private const DKIM = 'p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDrealKey123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(DnsResolver::class, new FakeDnsResolverForReceiving);
        config(['maildesk.providers.resend.api_key' => null]);
        Http::preventStrayRequests();
    }

    /**
     * @return array{0: User, 1: Organization, 2: Domain}
     */
    private function setUpDomainWithReceiving(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create(['region' => 'eu-west-1']);
        $org->users()->attach($user->id, ['role' => 'owner']);

        $domain = Domain::factory()->create([
            'organization_id' => $org->id,
            'name' => 'acme.test',
            'provider' => 'resend',
            'provider_domain_id' => 'dom_recv_test',
            'dns_records' => [
                'records' => [
                    ['key' => 'dkim', 'type' => 'TXT', 'name' => 'resend._domainkey', 'value' => self::DKIM, 'label' => 'DKIM'],
                    ['key' => 'return_path', 'type' => 'CNAME', 'name' => 'rsend', 'value' => 'send.forge.rmta.net', 'label' => 'CNAME (return path)'],
                    ['key' => 'inbound_mx', 'type' => 'MX', 'name' => '@', 'value' => 'inbound-smtp.eu-west-1.amazonaws.com', 'priority' => 10, 'label' => 'MX (receiving)'],
                    ['key' => 'dmarc', 'type' => 'TXT', 'name' => '_dmarc.acme.test', 'value' => 'v=DMARC1; p=none;', 'label' => 'DMARC'],
                ],
                'checks' => ['dkim' => false, 'return_path' => false, 'inbound_mx' => false, 'dmarc' => false],
            ],
        ]);

        return [$user, $org, $domain];
    }

    private function connect(Domain $domain, Organization $org): DnsConnection
    {
        return DnsConnection::create([
            'organization_id' => $org->id,
            'domain_id' => $domain->id,
            'provider' => 'cloudflare',
            'credentials' => ['api_token' => self::TOKEN],
            'zone_id' => 'zone1',
            'zone_name' => 'acme.test',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $live
     */
    private function fakeCloudflare(array $live): void
    {
        Http::fake([
            self::CF.'/zones?*' => Http::response(['success' => true, 'result' => [['id' => 'zone1', 'name' => 'acme.test']]]),
            self::CF.'/zones/zone1/dns_records?*' => Http::response(['success' => true, 'result' => $live]),
            self::CF.'/zones/zone1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'new']]),
            self::CF.'/zones/zone1/dns_records/*' => Http::response(['success' => true, 'result' => ['id' => 'x']]),
        ]);
    }

    private function as(User $user, Organization $org)
    {
        return $this->actingAs($user)->withSession(['current_organization_id' => $org->id]);
    }

    public function test_inbound_mx_is_auto_published_when_no_existing_mx(): void
    {
        [, $org, $domain] = $this->setUpDomainWithReceiving();
        $this->connect($domain, $org);
        $this->fakeCloudflare([
            ['id' => 'd1', 'type' => 'TXT', 'name' => 'resend._domainkey.acme.test', 'content' => self::DKIM],
            ['id' => 'c1', 'type' => 'CNAME', 'name' => 'rsend.acme.test', 'content' => 'send.forge.rmta.net'],
        ]);

        app(DomainVerifier::class)->verify($domain->fresh());

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && $r['type'] === 'MX'
            && $r['name'] === 'acme.test'
            && $r['content'] === 'inbound-smtp.eu-west-1.amazonaws.com'
            && $r['priority'] === 10);

        $publish = $domain->fresh()->dns_records['auto_publish'];
        $this->assertFalse($publish['skipped_inbound_mx']);
    }

    public function test_inbound_mx_is_skipped_when_existing_mx_records(): void
    {
        [, $org, $domain] = $this->setUpDomainWithReceiving();
        $this->connect($domain, $org);
        $this->fakeCloudflare([
            ['id' => 'd1', 'type' => 'TXT', 'name' => 'resend._domainkey.acme.test', 'content' => self::DKIM],
            ['id' => 'c1', 'type' => 'CNAME', 'name' => 'rsend.acme.test', 'content' => 'send.forge.rmta.net'],
            ['id' => 'mx1', 'type' => 'MX', 'name' => 'acme.test', 'content' => 'aspmx.l.google.com', 'priority' => 1],
            ['id' => 'mx2', 'type' => 'MX', 'name' => 'acme.test', 'content' => 'alt1.aspmx.l.google.com', 'priority' => 5],
        ]);

        app(DomainVerifier::class)->verify($domain->fresh());

        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && $r['type'] === 'MX'
            && $r['name'] === 'acme.test'
            && str_contains($r['content'], 'inbound-smtp'));

        $publish = $domain->fresh()->dns_records['auto_publish'];
        $this->assertTrue($publish['skipped_inbound_mx']);

        $warnings = $domain->fresh()->dns_records['warnings'];
        $this->assertNotEmpty($warnings);
        $this->assertTrue(
            collect($warnings)->contains(fn ($w) => str_contains($w, 'left untouched')
                && str_contains($w, 'MailDesk receiving was not enabled')),
            'Expected a warning that the existing mail provider MX records were left untouched',
        );
    }

    public function test_enable_receiving_publishes_mx_even_with_existing_records(): void
    {
        [$user, $org, $domain] = $this->setUpDomainWithReceiving();
        $this->connect($domain, $org);
        $this->fakeCloudflare([
            ['id' => 'd1', 'type' => 'TXT', 'name' => 'resend._domainkey.acme.test', 'content' => self::DKIM],
            ['id' => 'c1', 'type' => 'CNAME', 'name' => 'rsend.acme.test', 'content' => 'send.forge.rmta.net'],
            ['id' => 'mx1', 'type' => 'MX', 'name' => 'acme.test', 'content' => 'aspmx.l.google.com', 'priority' => 1],
        ]);

        $this->as($user, $org)
            ->from(route('domains.show', $domain))
            ->post(route('domains.dns.enable-receiving', $domain), ['confirm' => true])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Receiving enabled'));

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && $r['type'] === 'MX'
            && $r['name'] === 'acme.test'
            && str_contains($r['content'], 'inbound-smtp'));
    }

    public function test_enable_receiving_without_confirm_warns_about_existing_mx(): void
    {
        [$user, $org, $domain] = $this->setUpDomainWithReceiving();
        $this->connect($domain, $org);
        $this->fakeCloudflare([
            ['id' => 'd1', 'type' => 'TXT', 'name' => 'resend._domainkey.acme.test', 'content' => self::DKIM],
            ['id' => 'mx1', 'type' => 'MX', 'name' => 'acme.test', 'content' => 'aspmx.l.google.com', 'priority' => 1],
        ]);

        $this->as($user, $org)
            ->from(route('domains.show', $domain))
            ->post(route('domains.dns.enable-receiving', $domain))
            ->assertSessionHas('receiving_confirmation', fn ($data) => $data['required'] === true
                && str_contains($data['existing_mx'], 'aspmx.l.google.com')
                && str_contains($data['message'], 'already has MX records'));

        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['type'] === 'MX');

        $this->get(route('domains.show', $domain))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Domains/Show')
                ->where('flash.receiving_confirmation.required', true)
                ->where('flash.receiving_confirmation.existing_mx', 'aspmx.l.google.com')
                ->where('flash.receiving_confirmation.message', 'acme.test already has MX records (aspmx.l.google.com). Adding MailDesk\'s receiving MX may change where email is delivered, depending on MX priorities.'));
    }

    public function test_enable_receiving_returns_cloudflare_error_when_mx_lookup_fails(): void
    {
        [$user, $org, $domain] = $this->setUpDomainWithReceiving();
        $this->connect($domain, $org);
        Http::fake([
            self::CF.'/zones/zone1/dns_records*' => Http::response([
                'success' => false,
                'errors' => [['message' => 'Invalid request']],
            ], 403),
        ]);

        $this->as($user, $org)
            ->from(route('domains.show', $domain))
            ->post(route('domains.dns.enable-receiving', $domain))
            ->assertRedirect(route('domains.show', $domain))
            ->assertSessionHas('error', 'Cloudflare refused the request: Invalid request');

        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['type'] === 'MX');
    }

    public function test_apply_says_existing_mx_records_were_left_untouched(): void
    {
        [$user, $org, $domain] = $this->setUpDomainWithReceiving();
        $this->connect($domain, $org);
        $this->fakeCloudflare([
            ['id' => 'mx1', 'type' => 'MX', 'name' => 'acme.test', 'content' => 'aspmx.l.google.com', 'priority' => 1],
        ]);

        $this->as($user, $org)
            ->from(route('domains.show', $domain))
            ->post(route('domains.dns.apply', $domain))
            ->assertSessionHas('success', fn ($message) => str_contains($message, "The existing mail provider's MX records were left untouched")
                && str_contains($message, 'MailDesk receiving was not enabled'));
    }

    public function test_enable_receiving_without_dns_connection_shows_error(): void
    {
        [$user, $org, $domain] = $this->setUpDomainWithReceiving();
        Http::fake();

        $this->as($user, $org)
            ->from(route('domains.show', $domain))
            ->post(route('domains.dns.enable-receiving', $domain))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Connect a DNS provider'));
    }

    public function test_enable_receiving_without_inbound_mx_record_shows_error(): void
    {
        [$user, $org, $domain] = $this->setUpDomainWithReceiving();
        $domain->forceFill([
            'dns_records' => [
                'records' => [
                    ['key' => 'dkim', 'type' => 'TXT', 'name' => 'resend._domainkey', 'value' => self::DKIM, 'label' => 'DKIM'],
                ],
                'checks' => ['dkim' => false],
            ],
        ])->save();
        $this->connect($domain, $org);
        $this->fakeCloudflare([]);

        $this->as($user, $org)
            ->from(route('domains.show', $domain))
            ->post(route('domains.dns.enable-receiving', $domain))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'No receiving MX record'));
    }

    public function test_existing_maildesk_inbound_mx_is_not_considered_conflict(): void
    {
        [, $org, $domain] = $this->setUpDomainWithReceiving();
        $this->connect($domain, $org);
        $this->fakeCloudflare([
            ['id' => 'd1', 'type' => 'TXT', 'name' => 'resend._domainkey.acme.test', 'content' => self::DKIM],
            ['id' => 'c1', 'type' => 'CNAME', 'name' => 'rsend.acme.test', 'content' => 'send.forge.rmta.net'],
            ['id' => 'mx1', 'type' => 'MX', 'name' => 'acme.test', 'content' => 'inbound-smtp.us-east-1.amazonaws.com', 'priority' => 10],
        ]);

        app(DomainVerifier::class)->verify($domain->fresh());

        $publish = $domain->fresh()->dns_records['auto_publish'];
        $this->assertFalse($publish['skipped_inbound_mx']);
    }

    public function test_resend_sync_enables_receiving_capability(): void
    {
        config(['maildesk.providers.resend.api_key' => 're_test_key']);
        $user = User::factory()->create();
        $org = Organization::factory()->create(['region' => 'eu-west-1']);
        $org->users()->attach($user->id, ['role' => 'owner']);

        $domain = Domain::factory()->create([
            'organization_id' => $org->id,
            'name' => 'recv.test',
            'provider' => 'resend',
            'provider_domain_id' => 'dom_recv',
        ]);

        $records = [
            ['record' => 'DKIM', 'name' => 'resend._domainkey', 'type' => 'TXT', 'value' => self::DKIM],
            ['record' => 'SPF', 'name' => 'rsend', 'type' => 'CNAME', 'value' => 'send.forge.rmta.net'],
        ];

        $recordsWithReceiving = [
            ...$records,
            ['record' => 'Receiving', 'name' => '@', 'type' => 'MX', 'value' => 'inbound-smtp.eu-west-1.amazonaws.com', 'priority' => 10],
        ];

        $enableReceivingCalled = false;

        Http::fake(function (HttpRequest $r) use ($records, $recordsWithReceiving, &$enableReceivingCalled) {
            if ($r->method() === 'PATCH' && str_ends_with($r->url(), '/domains/dom_recv')) {
                if (isset($r['capabilities']['receiving']) && $r['capabilities']['receiving'] === 'enabled') {
                    $enableReceivingCalled = true;
                }

                return Http::response(['id' => 'dom_recv']);
            }

            if ($r->method() === 'GET' && str_ends_with($r->url(), '/domains/dom_recv')) {
                return Http::response([
                    'id' => 'dom_recv',
                    'name' => 'recv.test',
                    'status' => 'pending',
                    'region' => 'eu-west-1',
                    'capabilities' => ['sending' => 'enabled', 'receiving' => $enableReceivingCalled ? 'enabled' : 'disabled'],
                    'records' => $enableReceivingCalled ? $recordsWithReceiving : $records,
                ]);
            }

            if (str_ends_with($r->url(), '/verify')) {
                return Http::response(['id' => 'dom_recv']);
            }

            return Http::response(['message' => 'unexpected '.$r->url()], 500);
        });

        app(DomainVerifier::class)->verify($domain->fresh());

        $this->assertTrue($enableReceivingCalled, 'Expected enableReceiving to be called');

        $dns = $domain->fresh()->dns_records;
        $inboundMx = collect($dns['records'])->firstWhere('key', 'inbound_mx');
        $this->assertNotNull($inboundMx, 'Inbound MX record should be present');
        $this->assertSame('inbound-smtp.eu-west-1.amazonaws.com', $inboundMx['value']);
        $this->assertSame(10, $inboundMx['priority']);
    }

    public function test_resend_receiving_patch_is_skipped_when_already_enabled(): void
    {
        config(['maildesk.providers.resend.api_key' => 're_test_key']);
        $user = User::factory()->create();
        $org = Organization::factory()->create(['region' => 'eu-west-1']);
        $org->users()->attach($user->id, ['role' => 'owner']);

        $domain = Domain::factory()->create([
            'organization_id' => $org->id,
            'name' => 'recv.test',
            'provider' => 'resend',
            'provider_domain_id' => 'dom_recv',
        ]);

        $records = [
            ['record' => 'DKIM', 'name' => 'resend._domainkey', 'type' => 'TXT', 'value' => self::DKIM],
            ['record' => 'SPF', 'name' => 'rsend', 'type' => 'CNAME', 'value' => 'send.forge.rmta.net'],
            ['record' => 'Receiving', 'name' => '@', 'type' => 'MX', 'value' => 'inbound-smtp.eu-west-1.amazonaws.com', 'priority' => 10],
        ];

        Http::fake(function (HttpRequest $r) use ($records) {
            if ($r->method() === 'GET' && str_ends_with($r->url(), '/domains/dom_recv')) {
                return Http::response([
                    'id' => 'dom_recv',
                    'name' => 'recv.test',
                    'status' => 'pending',
                    'region' => 'eu-west-1',
                    'capabilities' => ['sending' => 'enabled', 'receiving' => 'enabled'],
                    'records' => $records,
                ]);
            }

            if ($r->method() === 'PATCH' && str_ends_with($r->url(), '/domains/dom_recv')) {
                return Http::response(['id' => 'dom_recv']);
            }

            if (str_ends_with($r->url(), '/verify')) {
                return Http::response(['id' => 'dom_recv']);
            }

            return Http::response(['message' => 'unexpected '.$r->url()], 500);
        });

        app(DomainVerifier::class)->verify($domain->fresh());
        app(DomainVerifier::class)->verify($domain->fresh());

        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/domains/dom_recv')
            && ($r['capabilities']['receiving'] ?? null) === 'enabled');
    }

    public function test_find_existing_root_mx_detects_third_party_mx(): void
    {
        [, $org, $domain] = $this->setUpDomainWithReceiving();
        $connection = $this->connect($domain, $org);
        $this->fakeCloudflare([
            ['id' => 'mx1', 'type' => 'MX', 'name' => 'acme.test', 'content' => 'mx.zoho.com', 'priority' => 10],
            ['id' => 'mx2', 'type' => 'MX', 'name' => 'send.acme.test', 'content' => 'feedback-smtp.eu-west-1.amazonses.com', 'priority' => 10],
        ]);

        $manager = app(DnsRecordManager::class);
        $existing = $manager->findExistingRootMx($domain, $connection);

        $this->assertCount(1, $existing);
        $this->assertSame('mx.zoho.com', $existing[0]['content']);
    }

    public function test_find_existing_root_mx_ignores_maildesk_inbound(): void
    {
        [, $org, $domain] = $this->setUpDomainWithReceiving();
        $connection = $this->connect($domain, $org);
        $this->fakeCloudflare([
            ['id' => 'mx1', 'type' => 'MX', 'name' => 'acme.test', 'content' => 'inbound-smtp.eu-west-1.amazonaws.com', 'priority' => 10],
        ]);

        $manager = app(DnsRecordManager::class);
        $existing = $manager->findExistingRootMx($domain, $connection);

        $this->assertEmpty($existing);
    }
}

class FakeDnsResolverForReceiving extends DnsResolver
{
    /** @var array<string, list<string>> */
    public array $txt = [];

    /** @var array<string, list<array{host: string, priority: int}>> */
    public array $mx = [];

    /** @var array<string, list<string>> */
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
