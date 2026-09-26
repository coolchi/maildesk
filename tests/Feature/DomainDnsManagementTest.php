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
use Tests\TestCase;

class DomainDnsManagementTest extends TestCase
{
    use RefreshDatabase;

    private const CF = 'https://api.cloudflare.com/client/v4';

    private const TOKEN = 'cf-test-token-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(DnsResolver::class, new FakeDnsResolver);
        config(['maildesk.providers.resend.api_key' => null]);
        Http::preventStrayRequests();
    }

    /**
     * @return array{0: User, 1: Organization, 2: Domain}
     */
    private function setUpDomain(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create(['region' => 'eu-west-1']);
        $org->users()->attach($user->id, ['role' => 'owner']);

        $domain = Domain::factory()->create([
            'organization_id' => $org->id,
            'name' => 'in.example.com',
            'dns_records' => [
                'records' => [
                    ['key' => 'dkim', 'type' => 'TXT', 'name' => 'resend._domainkey.in', 'value' => 'p=KEY', 'label' => 'DKIM'],
                    ['key' => 'mx', 'type' => 'MX', 'name' => 'send.in', 'value' => 'feedback-smtp.eu-west-1.amazonses.com', 'priority' => 10, 'label' => 'MX'],
                    ['key' => 'spf', 'type' => 'TXT', 'name' => 'send.in', 'value' => 'v=spf1 include:amazonses.com ~all', 'label' => 'SPF'],
                    ['key' => 'dmarc', 'type' => 'TXT', 'name' => '_dmarc.in.example.com', 'value' => 'v=DMARC1; p=none;', 'label' => 'DMARC'],
                ],
                'checks' => ['spf' => false, 'dkim' => false, 'dmarc' => false, 'mx' => false],
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
            'zone_name' => 'example.com',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $live
     */
    private function fakeCloudflare(array $live): void
    {
        Http::fake([
            self::CF.'/zones?name=in.example.com' => Http::response(['success' => true, 'result' => []]),
            self::CF.'/zones?name=example.com' => Http::response(['success' => true, 'result' => [['id' => 'zone1', 'name' => 'example.com']]]),
            self::CF.'/zones/zone1/dns_records?*' => Http::response(['success' => true, 'result' => $live]),
            self::CF.'/zones/zone1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'new']]),
            self::CF.'/zones/zone1/dns_records/*' => Http::response(['success' => true, 'result' => ['id' => 'x']]),
        ]);
    }

    private function as(User $user, Organization $org)
    {
        return $this->actingAs($user)->withSession(['current_organization_id' => $org->id]);
    }

    public function test_connect_finds_parent_zone_and_encrypts_token(): void
    {
        [$user, $org, $domain] = $this->setUpDomain();
        $this->fakeCloudflare([]);

        $this->as($user, $org)->from(route('domains.show', $domain))
            ->post(route('domains.dns.connect', $domain), ['provider' => 'cloudflare', 'api_token' => self::TOKEN])
            ->assertSessionHas('success');

        $connection = $domain->fresh()->dnsConnection;
        $this->assertSame('example.com', $connection->zone_name);
        $this->assertSame(self::TOKEN, $connection->credentials['api_token']);
        $this->assertStringNotContainsString(self::TOKEN, (string) $connection->getRawOriginal('credentials'));
    }

    public function test_connect_rejects_token_without_zone_access(): void
    {
        [$user, $org, $domain] = $this->setUpDomain();
        Http::fake([self::CF.'/zones*' => Http::response(['success' => true, 'result' => []])]);

        $this->as($user, $org)->from(route('domains.show', $domain))
            ->post(route('domains.dns.connect', $domain), ['provider' => 'cloudflare', 'api_token' => self::TOKEN])
            ->assertSessionHasErrors('api_token');

        $this->assertNull($domain->fresh()->dnsConnection);
    }

    public function test_plan_marks_records_ok_missing_and_different(): void
    {
        [, $org, $domain] = $this->setUpDomain();
        $connection = $this->connect($domain, $org);
        $this->fakeCloudflare([
            ['id' => 'd1', 'type' => 'TXT', 'name' => 'resend._domainkey.in.example.com', 'content' => 'p=KEY'],
            ['id' => 's1', 'type' => 'TXT', 'name' => 'send.in.example.com', 'content' => 'v=spf1 include:_spf.google.com ~all'],
            ['id' => 'w1', 'type' => 'A', 'name' => 'www.example.com', 'content' => '1.2.3.4'],
        ]);

        $plan = collect(app(DnsRecordManager::class)->plan($domain, $connection)['records'])->keyBy('key');

        $this->assertSame('ok', $plan['dkim']['state']);
        $this->assertSame('missing', $plan['mx']['state']);
        $this->assertSame('send.in.example.com', $plan['mx']['host']);
        $this->assertSame('different', $plan['spf']['state']);
        $this->assertSame('missing', $plan['dmarc']['state']);
        $this->assertSame('_dmarc.in.example.com', $plan['dmarc']['host']);
    }

    public function test_apply_creates_missing_and_merges_spf(): void
    {
        [$user, $org, $domain] = $this->setUpDomain();
        $this->connect($domain, $org);
        $this->fakeCloudflare([
            ['id' => 'd1', 'type' => 'TXT', 'name' => 'resend._domainkey.in.example.com', 'content' => 'p=KEY'],
            ['id' => 's1', 'type' => 'TXT', 'name' => 'send.in.example.com', 'content' => 'v=spf1 include:_spf.google.com ~all'],
        ]);

        $this->as($user, $org)->from(route('domains.show', $domain))
            ->post(route('domains.dns.apply', $domain))
            ->assertSessionHas('success', fn ($m) => str_contains($m, '2 added, 1 updated'));

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/dns_records/s1')
            && $r['content'] === 'v=spf1 include:_spf.google.com include:amazonses.com ~all');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && $r['type'] === 'MX' && $r['name'] === 'send.in.example.com' && $r['priority'] === 10);
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['type'] === 'TXT' && str_starts_with((string) $r['content'], 'v=spf1'));
    }

    public function test_cannot_edit_or_delete_records_outside_mail_hosts(): void
    {
        [$user, $org, $domain] = $this->setUpDomain();
        $this->connect($domain, $org);
        $this->fakeCloudflare([
            ['id' => 'w1', 'type' => 'A', 'name' => 'www.example.com', 'content' => '1.2.3.4'],
        ]);

        $this->as($user, $org)->from(route('domains.show', $domain))
            ->delete(route('domains.dns.records.destroy', [$domain, 'w1']))
            ->assertSessionHas('error');

        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'DELETE');
    }

    public function test_other_organizations_cannot_manage_dns(): void
    {
        [, $org, $domain] = $this->setUpDomain();
        $this->connect($domain, $org);
        [$intruder, $otherOrg] = (function () {
            $u = User::factory()->create();
            $o = Organization::factory()->create();
            $o->users()->attach($u->id, ['role' => 'owner']);

            return [$u, $o];
        })();
        Http::fake();

        $this->as($intruder, $otherOrg)->post(route('domains.dns.apply', $domain))->assertNotFound();
        $this->as($intruder, $otherOrg)->delete(route('domains.dns.disconnect', $domain))->assertNotFound();
        Http::assertNothingSent();
    }

    private function addReturnPathRow(Domain $domain): void
    {
        $dns = $domain->dns_records;
        array_splice($dns['records'], 3, 0, [['key' => 'return_path', 'type' => 'CNAME', 'name' => 'rsend.in', 'value' => 'send.forge.rmta.net', 'label' => 'CNAME (return path)']]);
        $domain->forceFill(['dns_records' => $dns])->save();
    }

    public function test_verification_auto_publishes_missing_records_including_cname(): void
    {
        [, $org, $domain] = $this->setUpDomain();
        $this->addReturnPathRow($domain);
        $this->connect($domain, $org);
        $this->fakeCloudflare([
            ['id' => 'd1', 'type' => 'TXT', 'name' => 'resend._domainkey.in.example.com', 'content' => 'p=KEY'],
        ]);

        app(DomainVerifier::class)->verify($domain->fresh());

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && $r['type'] === 'CNAME'
            && $r['name'] === 'rsend.in.example.com'
            && $r['content'] === 'send.forge.rmta.net'
            && $r['proxied'] === false);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['type'] === 'MX' && $r['name'] === 'send.in.example.com');
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['name'] === 'resend._domainkey.in.example.com');

        $publish = $domain->fresh()->dns_records['auto_publish'];
        // MX, SPF, CNAME and DMARC were missing; DKIM was already there.
        $this->assertSame(4, $publish['created']);
        $this->assertNull($publish['error']);
    }

    public function test_connecting_cloudflare_publishes_records_right_away(): void
    {
        [$user, $org, $domain] = $this->setUpDomain();
        $this->fakeCloudflare([]);

        $this->as($user, $org)->from(route('domains.show', $domain))
            ->post(route('domains.dns.connect', $domain), ['provider' => 'cloudflare', 'api_token' => self::TOKEN])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'published 4 records'));

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['name'] === 'resend._domainkey.in.example.com');
    }

    public function test_auto_publish_can_be_turned_off(): void
    {
        config(['maildesk.domains.auto_publish_dns' => false]);
        [, $org, $domain] = $this->setUpDomain();
        $this->connect($domain, $org);
        $this->fakeCloudflare([]);

        app(DomainVerifier::class)->verify($domain->fresh());

        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST');
        $this->assertArrayNotHasKey('auto_publish', $domain->fresh()->dns_records);
    }

    public function test_auto_publish_failure_is_recorded_and_verification_still_runs(): void
    {
        [, $org, $domain] = $this->setUpDomain();
        $this->connect($domain, $org);
        Http::fake([self::CF.'/*' => Http::response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]], 403)]);

        $result = app(DomainVerifier::class)->verify($domain->fresh());

        $this->assertFalse($result['verified']);
        $this->assertNotEmpty($domain->fresh()->dns_records['auto_publish']['error']);
        $this->assertNotNull($domain->fresh()->dns_records['checked_at']);
    }

    public function test_spf_merge_keeps_existing_mechanisms(): void
    {
        $m = app(DnsRecordManager::class);
        $this->assertSame('v=spf1 include:a.com include:amazonses.com -all', $m->mergeSpf('v=spf1 include:a.com -all', 'v=spf1 include:amazonses.com ~all'));
        $this->assertSame('v=spf1 include:amazonses.com ~all', $m->mergeSpf('v=spf1 include:amazonses.com ~all', 'v=spf1 include:amazonses.com ~all'));
    }
}
