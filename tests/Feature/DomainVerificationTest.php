<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Organization;
use App\Models\User;
use App\Services\Domains\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DomainVerificationTest extends TestCase
{
    use RefreshDatabase;

    private FakeDnsResolver $dns;

    private const DKIM = 'p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDrealKey123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $this->dns);

        // No Resend key by default: DNS-only verification.
        config(['maildesk.providers.resend.api_key' => null]);
        Http::preventStrayRequests();
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function member(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create(['region' => 'eu-west-1']);
        $org->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $org];
    }

    private function verify(User $user, Organization $org, Domain $domain)
    {
        return $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->from(route('domains.show', $domain))
            ->post(route('domains.verify', $domain));
    }

    private function publishDefaultRecords(string $name): void
    {
        $this->dns->txt[$name] = ['v=spf1 include:amazonses.com ~all'];
        $this->dns->txt["resend._domainkey.{$name}"] = [self::DKIM];
        $this->dns->txt["_dmarc.{$name}"] = ['v=DMARC1; p=none;'];
    }

    public function test_domain_with_no_dns_records_fails(): void
    {
        [$user, $org] = $this->member();
        $domain = Domain::factory()->create(['organization_id' => $org->id, 'name' => 'nodns.test']);

        $this->verify($user, $org, $domain)
            ->assertRedirect(route('domains.show', $domain))
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'Not detected in DNS: SPF, DKIM.'));

        $domain->refresh();
        $this->assertSame('failed', $domain->status);
        $this->assertNull($domain->verified_at);
        $checks = $domain->dns_records['checks'];
        $this->assertFalse($checks['spf']);
        $this->assertFalse($checks['dkim']);
        $this->assertFalse($checks['dmarc']);
        $this->assertFalse($checks['mx']);
        $this->assertNotNull($domain->dns_records['checked_at']);
        $this->assertSame(['spf', 'dkim'], $domain->dns_records['required']);
    }

    public function test_domain_with_correct_records_passes(): void
    {
        [$user, $org] = $this->member();
        $domain = Domain::factory()->create(['organization_id' => $org->id, 'name' => 'good.test']);
        $this->publishDefaultRecords('good.test');
        $this->dns->mx['good.test'] = [['host' => 'mx.good.test', 'priority' => 10]];

        $this->verify($user, $org, $domain)->assertSessionHas('success', 'good.test verified.');

        $domain->refresh();
        $this->assertSame('verified', $domain->status);
        $this->assertNotNull($domain->verified_at);
        $this->assertEquals(
            ['spf' => true, 'dkim' => true, 'dmarc' => true, 'mx' => true],
            array_intersect_key($domain->dns_records['checks'], array_flip(['spf', 'dkim', 'dmarc', 'mx'])),
        );
        $this->assertSame([self::DKIM], $domain->dns_records['results']['dkim']['found']);
        $this->assertSame([], $domain->dns_records['warnings']);
    }

    public function test_missing_dmarc_is_a_warning_but_domain_still_passes(): void
    {
        [$user, $org] = $this->member();
        $domain = Domain::factory()->create(['organization_id' => $org->id, 'name' => 'nodmarc.test']);
        $this->publishDefaultRecords('nodmarc.test');
        unset($this->dns->txt['_dmarc.nodmarc.test']);

        $this->verify($user, $org, $domain)
            ->assertSessionHas('success', fn (string $m) => str_starts_with($m, 'nodmarc.test verified.') && str_contains($m, 'Warning: DMARC'));

        $domain->refresh();
        $this->assertSame('verified', $domain->status);
        $this->assertNotNull($domain->verified_at);
        $this->assertFalse($domain->dns_records['checks']['dmarc']);
        $this->assertCount(1, $domain->dns_records['warnings']);
        $this->assertStringContainsString('_dmarc.nodmarc.test', $domain->dns_records['warnings'][0]);

        // An invalid DMARC record is treated the same way.
        $this->dns->txt['_dmarc.nodmarc.test'] = ['p=none; not-a-dmarc-record'];
        $this->artisan('domains:recheck --all')->assertSuccessful();
        $domain->refresh();
        $this->assertSame('verified', $domain->status);
        $this->assertNotEmpty($domain->dns_records['warnings']);

        // Publishing a correct DMARC record clears the warning.
        $this->dns->txt['_dmarc.nodmarc.test'] = ['v=DMARC1; p=quarantine;'];
        $this->artisan('domains:recheck --all')->assertSuccessful();
        $domain->refresh();
        $this->assertSame('verified', $domain->status);
        $this->assertTrue($domain->dns_records['checks']['dmarc']);
        $this->assertSame([], $domain->dns_records['warnings']);
    }

    public function test_partial_or_wrong_records_fail_with_the_failing_checks_named(): void
    {
        [$user, $org] = $this->member();
        $domain = Domain::factory()->create(['organization_id' => $org->id, 'name' => 'partial.test']);
        $this->publishDefaultRecords('partial.test');
        // SPF without the required include, and no DMARC.
        $this->dns->txt['partial.test'] = ['v=spf1 include:_spf.google.com ~all'];
        unset($this->dns->txt['_dmarc.partial.test']);

        $this->verify($user, $org, $domain)
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'Not detected in DNS: SPF.'));

        $domain->refresh();
        $this->assertSame('failed', $domain->status);
        $this->assertTrue($domain->dns_records['checks']['dkim']);
        $this->assertFalse($domain->dns_records['checks']['spf']);
        $this->assertSame(['v=spf1 include:_spf.google.com ~all'], $domain->dns_records['results']['spf']['found']);
        $this->assertFalse($domain->dns_records['checks']['dmarc']);
        $this->assertNotEmpty($domain->dns_records['warnings']);
    }

    public function test_verify_registers_domain_with_resend_and_checks_resend_records(): void
    {
        config(['maildesk.providers.resend.api_key' => 're_test_key']);
        [$user, $org] = $this->member();
        // Subdomain on purpose: Resend returns names relative to the apex.
        $domain = Domain::factory()->create(['organization_id' => $org->id, 'name' => 'in.acme.test']);

        $records = [
            ['record' => 'SPF', 'name' => 'send.in', 'type' => 'MX', 'value' => 'feedback-smtp.eu-west-1.amazonses.com', 'priority' => 10],
            ['record' => 'SPF', 'name' => 'send.in', 'type' => 'TXT', 'value' => '"v=spf1 include:amazonses.com ~all"'],
            ['record' => 'DKIM', 'name' => 'resend._domainkey.in', 'type' => 'TXT', 'value' => self::DKIM],
        ];

        Http::fake([
            'api.resend.com/domains/dom_123/verify' => Http::response(['object' => 'domain', 'id' => 'dom_123']),
            'api.resend.com/domains/dom_123' => Http::response(['id' => 'dom_123', 'name' => 'in.acme.test', 'status' => 'pending', 'region' => 'eu-west-1', 'records' => $records]),
            'api.resend.com/domains' => Http::response(['id' => 'dom_123', 'name' => 'in.acme.test', 'status' => 'not_started', 'records' => $records], 201),
        ]);

        $this->dns->mx['send.in.acme.test'] = [['host' => 'feedback-smtp.eu-west-1.amazonses.com', 'priority' => 10]];
        $this->dns->txt['send.in.acme.test'] = ['v=spf1 include:amazonses.com ~all'];
        $this->dns->txt['resend._domainkey.in.acme.test'] = [self::DKIM];
        $this->dns->txt['_dmarc.in.acme.test'] = ['v=DMARC1; p=none;'];

        $this->verify($user, $org, $domain)->assertSessionHas('error');

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && $r->url() === 'https://api.resend.com/domains'
            && $r['name'] === 'in.acme.test'
            && $r['region'] === 'eu-west-1'
            && $r->hasHeader('Authorization', 'Bearer re_test_key'));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/domains/dom_123/verify')
            && $r->body() === '{}');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH'
            && $r->url() === 'https://api.resend.com/domains/dom_123'
            && ($r['open_tracking'] ?? null) === true
            && ($r['click_tracking'] ?? null) === true
            && ($r['tracking_subdomain'] ?? null) === 'links');

        $domain->refresh();
        $this->assertSame('dom_123', $domain->provider_domain_id);
        $this->assertSame('pending', $domain->status);
        $this->assertSame('pending', $domain->dns_records['provider']['status']);
        $this->assertSame(['spf', 'dkim', 'mx'], $domain->dns_records['required']);
        $this->assertSame([], $domain->dns_records['warnings']);
        $this->assertTrue($domain->dns_records['checks']['mx']);
        // The fake placeholder records are replaced by Resend's real ones (plus DMARC).
        $keys = array_column($domain->dns_records['records'], 'key');
        $this->assertSame(['mx', 'spf', 'dkim', 'dmarc'], $keys);
        $dkimRow = collect($domain->dns_records['records'])->firstWhere('key', 'dkim');
        $this->assertSame(self::DKIM, $dkimRow['value']);
        $this->assertContains('resend._domainkey.in.acme.test', $dkimRow['hosts']);
    }

    public function test_resend_cname_return_path_without_spf_txt_still_verifies(): void
    {
        config(['maildesk.providers.resend.api_key' => 're_test_key']);
        [$user, $org] = $this->member();
        $domain = Domain::factory()->create([
            'organization_id' => $org->id,
            'name' => 'in.desk.test',
            'provider_domain_id' => 'dom_cname',
        ]);

        $records = [
            ['record' => 'DKIM', 'name' => 'resend._domainkey.in', 'type' => 'TXT', 'value' => self::DKIM],
            ['record' => 'SPF', 'name' => 'rsend.in', 'type' => 'CNAME', 'value' => 'rsend-euw1.forge.rmta.net'],
            ['record' => 'SPF', 'name' => 'send.in', 'type' => 'CNAME', 'value' => 'send.forge.rmta.net'],
            ['record' => 'Receiving', 'name' => 'in', 'type' => 'MX', 'value' => 'inbound-smtp.eu-west-1.amazonaws.com', 'priority' => 10],
        ];

        Http::fake([
            'api.resend.com/domains/dom_cname/verify' => Http::response(['id' => 'dom_cname']),
            'api.resend.com/domains/dom_cname' => Http::response([
                'id' => 'dom_cname',
                'name' => 'in.desk.test',
                'status' => 'verified',
                'region' => 'eu-west-1',
                'records' => $records,
            ]),
        ]);

        $this->dns->txt['resend._domainkey.in.desk.test'] = [self::DKIM];
        $this->dns->cname['rsend.in.desk.test'] = ['rsend-euw1.forge.rmta.net'];
        $this->dns->cname['send.in.desk.test'] = ['send.forge.rmta.net'];
        $this->dns->mx['in.desk.test'] = [['host' => 'inbound-smtp.eu-west-1.amazonaws.com', 'priority' => 10]];
        $this->dns->txt['_dmarc.in.desk.test'] = ['v=DMARC1; p=none;'];

        $this->verify($user, $org, $domain)->assertSessionHas('success');

        $domain->refresh();
        $this->assertSame('verified', $domain->status);
        $this->assertSame(['dkim', 'return_path'], $domain->dns_records['required']);
        $this->assertArrayNotHasKey('spf', $domain->dns_records['checks']);
        $this->assertTrue($domain->dns_records['checks']['return_path']);
        $this->assertSame('verified', $domain->dns_records['provider']['status']);
    }

    public function test_deleted_resend_domain_id_is_replaced_by_recreated_domain(): void
    {
        config(['maildesk.providers.resend.api_key' => 're_test_key']);
        [$user, $org] = $this->member();
        $domain = Domain::factory()->create([
            'organization_id' => $org->id,
            'name' => 'recreated.test',
            'provider_domain_id' => 'dom_old',
        ]);

        Http::fake(function (HttpRequest $r) {
            return match (true) {
                $r->method() === 'GET' && str_ends_with($r->url(), '/domains/dom_old') => Http::response(['message' => 'Not found'], 404),
                $r->method() === 'GET' && $r->url() === 'https://api.resend.com/domains' => Http::response([
                    'data' => [['id' => 'dom_new', 'name' => 'recreated.test', 'status' => 'verified']],
                ]),
                $r->method() === 'GET' && str_ends_with($r->url(), '/domains/dom_new') => Http::response([
                    'id' => 'dom_new',
                    'name' => 'recreated.test',
                    'status' => 'verified',
                    'records' => [
                        ['record' => 'DKIM', 'name' => 'resend._domainkey', 'type' => 'TXT', 'value' => self::DKIM],
                        ['record' => 'SPF', 'name' => 'send', 'type' => 'TXT', 'value' => '"v=spf1 include:amazonses.com ~all"'],
                    ],
                ]),
                str_ends_with($r->url(), '/verify') => Http::response(['id' => 'dom_new']),
                default => Http::response(['message' => 'unexpected '.$r->url()], 500),
            };
        });

        $this->dns->txt['resend._domainkey.recreated.test'] = [self::DKIM];
        $this->dns->txt['send.recreated.test'] = ['v=spf1 include:amazonses.com ~all'];
        $this->dns->txt['_dmarc.recreated.test'] = ['v=DMARC1; p=none;'];

        $this->verify($user, $org, $domain)->assertSessionHas('success');

        $domain->refresh();
        $this->assertSame('dom_new', $domain->provider_domain_id);
        $this->assertSame('verified', $domain->status);
        $this->assertSame('dom_new', $domain->dns_records['provider']['id']);
    }

    public function test_resend_return_path_cname_is_listed_required_and_checked(): void
    {
        config(['maildesk.providers.resend.api_key' => 're_test_key']);
        [$user, $org] = $this->member();
        $domain = Domain::factory()->create(['organization_id' => $org->id, 'name' => 'in.acme.test', 'provider_domain_id' => 'dom_c']);

        $records = [
            ['record' => 'DKIM', 'name' => 'resend._domainkey.in', 'type' => 'TXT', 'value' => self::DKIM],
            ['record' => 'SPF', 'name' => 'send.in', 'type' => 'MX', 'value' => 'feedback-smtp.eu-west-1.amazonses.com', 'priority' => 10],
            ['record' => 'SPF', 'name' => 'send.in', 'type' => 'TXT', 'value' => '"v=spf1 include:amazonses.com ~all"'],
            ['record' => 'SPF', 'name' => 'rsend.in', 'type' => 'CNAME', 'value' => 'send.forge.rmta.net'],
        ];

        // Track whether DNS CNAME is set to simulate Resend verification state
        $cnameAdded = false;

        Http::fake(function (HttpRequest $r) use ($records, &$cnameAdded) {
            return match (true) {
                str_ends_with($r->url(), '/verify') => Http::response(['id' => 'dom_c']),
                str_ends_with($r->url(), '/domains/dom_c') => Http::response([
                    'id' => 'dom_c',
                    'status' => $cnameAdded ? 'verified' : 'partially_verified',
                    'records' => $records,
                ]),
                default => Http::response(['message' => 'unexpected '.$r->url()], 500),
            };
        });

        $this->dns->mx['send.in.acme.test'] = [['host' => 'feedback-smtp.eu-west-1.amazonses.com', 'priority' => 10]];
        $this->dns->txt['send.in.acme.test'] = ['v=spf1 include:amazonses.com ~all'];
        $this->dns->txt['resend._domainkey.in.acme.test'] = [self::DKIM];

        // Without the CNAME the domain is not verified, and the failing check is named.
        $this->verify($user, $org, $domain);
        $domain->refresh();
        $this->assertSame('failed', $domain->status);
        $this->assertContains('return_path', $domain->dns_records['required']);
        $this->assertFalse($domain->dns_records['checks']['return_path']);
        $row = collect($domain->dns_records['records'])->firstWhere('key', 'return_path');
        $this->assertSame('CNAME', $row['type']);
        $this->assertSame('send.forge.rmta.net', $row['value']);
        $this->assertContains('rsend.in.acme.test', $row['hosts']);

        // After adding the CNAME, Resend reports the domain as verified
        $this->dns->cname['rsend.in.acme.test'] = ['send.forge.rmta.net'];
        $cnameAdded = true;
        $this->verify($user, $org, $domain);
        $this->assertSame('verified', $domain->fresh()->status);
    }

    public function test_unknown_provider_records_are_kept_instead_of_dropped(): void
    {
        config(['maildesk.providers.resend.api_key' => 're_test_key']);
        [$user, $org] = $this->member();
        $domain = Domain::factory()->create(['organization_id' => $org->id, 'name' => 'in.acme.test', 'provider_domain_id' => 'dom_n']);

        Http::fake([
            'api.resend.com/domains/dom_n/verify' => Http::response(['id' => 'dom_n']),
            'api.resend.com/domains/dom_n' => Http::response(['id' => 'dom_n', 'status' => 'pending', 'records' => [
                ['record' => 'DKIM', 'name' => 'resend._domainkey.in', 'type' => 'TXT', 'value' => self::DKIM],
                ['record' => 'Tracking', 'name' => 'links.in', 'type' => 'CNAME', 'value' => 'track.example.net'],
            ]]),
        ]);

        $this->verify($user, $org, $domain);

        $records = $domain->fresh()->dns_records['records'];
        $tracking = collect($records)->firstWhere('key', 'tracking');
        $this->assertNotNull($tracking);
        $this->assertSame('CNAME (open and click tracking)', $tracking['label']);
        $this->assertSame('track.example.net', $tracking['value']);
        $this->assertContains('tracking', $domain->fresh()->dns_records['required']);
    }

    public function test_domain_already_on_resend_account_is_adopted(): void
    {
        config(['maildesk.providers.resend.api_key' => 're_test_key']);
        [$user, $org] = $this->member();
        $domain = Domain::factory()->create(['organization_id' => $org->id, 'name' => 'taken.test']);

        Http::fake(function (HttpRequest $r) {
            return match (true) {
                $r->method() === 'POST' && $r->url() === 'https://api.resend.com/domains' => Http::response(['message' => 'Domain already exists'], 403),
                $r->method() === 'GET' && $r->url() === 'https://api.resend.com/domains' => Http::response(['data' => [['id' => 'dom_old', 'name' => 'taken.test']]]),
                str_ends_with($r->url(), '/verify') => Http::response(['id' => 'dom_old']),
                default => Http::response(['id' => 'dom_old', 'name' => 'taken.test', 'status' => 'verified', 'records' => []]),
            };
        });

        $this->verify($user, $org, $domain);

        $this->assertSame('dom_old', $domain->refresh()->provider_domain_id);
    }

    public function test_already_registered_domain_is_not_registered_twice(): void
    {
        config(['maildesk.providers.resend.api_key' => 're_test_key']);
        [$user, $org] = $this->member();
        $domain = Domain::factory()->create([
            'organization_id' => $org->id,
            'name' => 'known.test',
            'provider_domain_id' => 'dom_known',
        ]);

        Http::fake([
            'api.resend.com/domains/dom_known/verify' => Http::response(['id' => 'dom_known']),
            'api.resend.com/domains/dom_known' => Http::response(['id' => 'dom_known', 'status' => 'pending', 'records' => []]),
        ]);

        $this->verify($user, $org, $domain);

        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r->url() === 'https://api.resend.com/domains');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/domains/dom_known/verify'));
    }

    public function test_stuck_partial_resend_domain_is_replaced_when_dns_already_matches(): void
    {
        config(['maildesk.providers.resend.api_key' => 're_test_key']);
        [$user, $org] = $this->member();
        $domain = Domain::factory()->create([
            'organization_id' => $org->id,
            'name' => 'stuck.test',
            'provider_domain_id' => 'dom_stuck',
        ]);

        $freshDkim = 'p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDfreshKey456';
        $stuckRecords = [
            ['record' => 'DKIM', 'name' => 'resend._domainkey', 'type' => 'TXT', 'value' => self::DKIM, 'status' => 'pending'],
            ['record' => 'SPF', 'name' => 'send', 'type' => 'TXT', 'value' => 'v=spf1 include:amazonses.com ~all', 'status' => 'pending'],
            ['record' => 'SPF', 'name' => 'send', 'type' => 'MX', 'value' => 'feedback-smtp.us-east-1.amazonses.com', 'priority' => 10, 'status' => 'pending'],
            ['record' => 'SPF', 'name' => 'rsend', 'type' => 'CNAME', 'value' => 'send.forge.rmta.net', 'status' => 'verified'],
        ];

        Http::fake(function (HttpRequest $r) use ($stuckRecords, $freshDkim) {
            return match (true) {
                $r->method() === 'DELETE' && str_ends_with($r->url(), '/domains/dom_stuck') => Http::response(['id' => 'dom_stuck', 'deleted' => true]),
                $r->method() === 'GET' && $r->url() === 'https://api.resend.com/domains' => Http::response(['data' => []]),
                $r->method() === 'POST' && $r->url() === 'https://api.resend.com/domains' => Http::response(['id' => 'dom_fresh', 'name' => 'stuck.test', 'status' => 'not_started'], 201),
                $r->method() === 'GET' && str_ends_with($r->url(), '/domains/dom_stuck') => Http::response([
                    'id' => 'dom_stuck',
                    'status' => 'partially_verified',
                    'region' => 'us-east-1',
                    'created_at' => now()->subHours(8)->toIso8601String(),
                    'records' => $stuckRecords,
                ]),
                $r->method() === 'GET' && str_ends_with($r->url(), '/domains/dom_fresh') => Http::response([
                    'id' => 'dom_fresh',
                    'status' => 'not_started',
                    'region' => 'us-east-1',
                    'created_at' => now()->toIso8601String(),
                    'records' => [
                        ['record' => 'DKIM', 'name' => 'resend._domainkey', 'type' => 'TXT', 'value' => $freshDkim],
                        ['record' => 'SPF', 'name' => 'send', 'type' => 'TXT', 'value' => 'v=spf1 include:amazonses.com ~all'],
                        ['record' => 'SPF', 'name' => 'send', 'type' => 'MX', 'value' => 'feedback-smtp.us-east-1.amazonses.com', 'priority' => 10],
                        ['record' => 'SPF', 'name' => 'rsend', 'type' => 'CNAME', 'value' => 'send.forge.rmta.net'],
                    ],
                ]),
                $r->method() === 'PATCH' => Http::response(['id' => 'dom_fresh']),
                str_ends_with($r->url(), '/verify') && $r->body() === '{}' => Http::response(['id' => 'dom_fresh']),
                default => Http::response(['message' => 'unexpected '.$r->method().' '.$r->url()], 500),
            };
        });

        $this->dns->txt['resend._domainkey.stuck.test'] = [self::DKIM];
        $this->dns->txt['send.stuck.test'] = ['v=spf1 include:amazonses.com ~all'];
        $this->dns->mx['send.stuck.test'] = [['host' => 'feedback-smtp.us-east-1.amazonses.com', 'priority' => 10]];
        $this->dns->cname['rsend.stuck.test'] = ['send.forge.rmta.net'];

        $this->verify($user, $org, $domain);

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/domains/dom_stuck'));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r->url() === 'https://api.resend.com/domains' && $r['region'] === 'us-east-1');

        $domain->refresh();
        $this->assertSame('dom_fresh', $domain->provider_domain_id);
        $this->assertSame(1, $domain->dns_records['provider']['replacements']);
        $dkim = collect($domain->dns_records['records'])->firstWhere('key', 'dkim');
        $this->assertSame($freshDkim, $dkim['value']);
    }

    public function test_resend_outage_does_not_block_dns_check_and_is_reported(): void
    {
        config(['maildesk.providers.resend.api_key' => 're_test_key']);
        [$user, $org] = $this->member();
        $domain = Domain::factory()->create(['organization_id' => $org->id, 'name' => 'outage.test']);
        $this->publishDefaultRecords('outage.test');

        Http::fake(['api.resend.com/*' => Http::response(['message' => 'boom'], 500)]);

        $this->verify($user, $org, $domain)->assertSessionHas('success');

        $domain->refresh();
        $this->assertSame('verified', $domain->status);
        $this->assertNull($domain->provider_domain_id);
        $this->assertStringContainsString('HTTP 500', $domain->dns_records['provider_error']);
    }

    public function test_status_persists_and_recheck_command_updates_it(): void
    {
        [, $org] = $this->member();
        $domain = Domain::factory()->create(['organization_id' => $org->id, 'name' => 'flip.test']);

        // Nothing published yet: the hourly re-check marks it failed.
        $this->artisan('domains:recheck')->assertSuccessful();
        $this->assertSame('failed', $domain->refresh()->status);

        // Records get published: the next re-check verifies it.
        $this->publishDefaultRecords('flip.test');
        $this->artisan('domains:recheck')->assertSuccessful();
        $domain->refresh();
        $this->assertSame('verified', $domain->status);
        $verifiedAt = $domain->verified_at;
        $this->assertNotNull($verifiedAt);

        // Verified domains are skipped by the hourly run...
        unset($this->dns->txt['resend._domainkey.flip.test']);
        $this->artisan('domains:recheck')->assertSuccessful();
        $this->assertSame('verified', $domain->refresh()->status);

        // ...but the daily --all run catches a removed DKIM record.
        $this->artisan('domains:recheck --all')->assertSuccessful();
        $domain->refresh();
        $this->assertSame('failed', $domain->status);
        $this->assertNull($domain->verified_at);
        $this->assertFalse($domain->dns_records['checks']['dkim']);
        $this->assertTrue($domain->dns_records['checks']['spf']);
    }

    public function test_show_page_exposes_real_check_results(): void
    {
        [$user, $org] = $this->member();
        $domain = Domain::factory()->create(['organization_id' => $org->id, 'name' => 'show.test']);
        $this->dns->txt['show.test'] = ['v=spf1 include:amazonses.com ~all'];
        $this->verify($user, $org, $domain);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('domains.show', $domain))
            ->assertInertia(fn ($page) => $page
                ->component('Domains/Show')
                ->where('domain.status', 'failed')
                ->where('domain.records.spf', true)
                ->where('domain.records.dkim', false)
                ->has('domain.checked_at')
                ->where('domain.results.spf.found.0', 'v=spf1 include:amazonses.com ~all'));
    }

    public function test_cannot_verify_another_organizations_domain(): void
    {
        [$user, $org] = $this->member();
        $foreign = Domain::factory()->create(['name' => 'foreign.test']);

        $this->verify($user, $org, $foreign)->assertNotFound();
        $this->assertSame('pending', $foreign->refresh()->status);
    }

    public function test_to_workspace_array_shows_partially_verified_when_provider_reports_it(): void
    {
        $org = Organization::factory()->create(['region' => 'us-east-1']);
        $domain = Domain::factory()->create([
            'organization_id' => $org->id,
            'name' => 'partial.test',
            'status' => 'verified',
            'dns_records' => [
                'checks' => ['spf' => true, 'dkim' => true, 'dmarc' => true],
                'records' => [
                    ['key' => 'spf', 'type' => 'TXT', 'name' => 'partial.test', 'label' => 'SPF'],
                    ['key' => 'dkim', 'type' => 'TXT', 'name' => 'resend._domainkey.partial.test', 'label' => 'DKIM'],
                ],
                'provider' => [
                    'status' => 'partially_verified',
                    'records' => [
                        ['record' => 'SPF', 'status' => 'verified'],
                        ['record' => 'DKIM', 'status' => 'pending'],
                    ],
                ],
            ],
        ]);

        $array = $domain->toWorkspaceArray();

        $this->assertSame('partially_verified', $array['status']);
        $this->assertSame('partially_verified', $array['provider_status']);
        $this->assertContains('DKIM', $array['pending_records']);
    }

    public function test_to_workspace_array_shows_verified_when_provider_is_fully_verified(): void
    {
        $org = Organization::factory()->create(['region' => 'us-east-1']);
        $domain = Domain::factory()->create([
            'organization_id' => $org->id,
            'name' => 'full.test',
            'status' => 'verified',
            'dns_records' => [
                'checks' => ['spf' => true, 'dkim' => true, 'dmarc' => true],
                'records' => [],
                'provider' => [
                    'status' => 'verified',
                ],
            ],
        ]);

        $array = $domain->toWorkspaceArray();

        $this->assertSame('verified', $array['status']);
        $this->assertSame('verified', $array['provider_status']);
        $this->assertEmpty($array['pending_records']);
    }
}

class FakeDnsResolver extends DnsResolver
{
    /** @var array<string, list<string>> */
    public array $txt = [];

    /** @var array<string, list<array{host: string, priority: int}>> */
    public array $mx = [];

    public function txt(string $host): array
    {
        return $this->txt[strtolower($host)] ?? [];
    }

    /** @var array<string, list<string>> */
    public array $cname = [];

    public function mx(string $host): array
    {
        return $this->mx[strtolower($host)] ?? [];
    }

    public function cname(string $host): array
    {
        return $this->cname[strtolower($host)] ?? [];
    }
}
