<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Organization;
use App\Models\OrganizationHost;
use App\Models\User;
use App\Services\Domains\HostRecordResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeHostRecordResolver;
use Tests\TestCase;

class AdminHostDnsVerificationTest extends TestCase
{
    use RefreshDatabase;

    private FakeHostRecordResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();
        config(['maildesk.base_domain' => 'maildesk.test', 'maildesk.custom_host_ips' => []]);
        Http::preventStrayRequests();
        $this->dns = new FakeHostRecordResolver;
        $this->app->instance(HostRecordResolver::class, $this->dns);
    }

    private function customHost(?Organization $org = null): OrganizationHost
    {
        $org ??= Organization::factory()->create();

        return OrganizationHost::query()->create([
            'organization_id' => $org->id, 'host' => 'mail.customer.com', 'subdomain' => null,
            'status' => 'pending_dns', 'ssl' => false, 'is_custom' => true,
        ]);
    }

    private function verify(OrganizationHost $host)
    {
        return $this->actingAs(User::factory()->platformAdmin()->create())
            ->post(route('admin.subdomains.verify', $host));
    }

    public function test_custom_host_with_cname_to_platform_becomes_active(): void
    {
        $host = $this->customHost();
        $this->dns->set('mail.customer.com', DNS_CNAME, ['edge.maildesk.test.']);

        $this->verify($host)->assertSessionHasNoErrors();

        $host->refresh();
        $this->assertSame('active', $host->status);
        $this->assertTrue($host->ssl);
        $this->assertTrue($host->dns_check['passed']);
        $this->assertNotNull($host->dns_checked_at);
    }

    public function test_custom_host_with_matching_a_record_becomes_active(): void
    {
        $host = $this->customHost();
        $this->dns->set('maildesk.test', DNS_A, ['203.0.113.10']);
        $this->dns->set('mail.customer.com', DNS_A, ['203.0.113.10']);

        $this->verify($host)->assertSessionHasNoErrors();
        $this->assertSame('active', $host->fresh()->status);
    }

    public function test_custom_host_pointing_elsewhere_stays_pending_and_records_the_failure(): void
    {
        $host = $this->customHost();
        $this->dns->set('maildesk.test', DNS_A, ['203.0.113.10']);
        $this->dns->set('mail.customer.com', DNS_A, ['198.51.100.7']);

        $this->verify($host)->assertSessionHasErrors('host');

        $host->refresh();
        $this->assertSame('pending_dns', $host->status);
        $this->assertFalse($host->ssl);
        $this->assertFalse($host->dns_check['passed']);
        $this->assertSame(['mail.customer.com points to the platform'], $host->dns_check['failed']);
        $this->assertStringContainsString('198.51.100.7', $host->dns_check['checks'][0]['detail']);

        $this->actingAs(User::factory()->platformAdmin()->create())->get(route('admin.subdomains'))
            ->assertInertia(fn (Assert $page) => $page->where('hosts.0.dnsCheck.passed', false));
    }

    public function test_verified_sending_domain_spf_and_dkim_are_required_and_dmarc_is_advisory(): void
    {
        $org = Organization::factory()->create();
        $host = $this->customHost($org);
        $this->dns->set('mail.customer.com', DNS_CNAME, ['maildesk.test']);
        Domain::factory()->verified()->create([
            'organization_id' => $org->id,
            'name' => 'customer.com',
            'dns_records' => [
                'records' => [
                    ['key' => 'spf', 'type' => 'TXT', 'name' => 'send', 'value' => 'v=spf1 include:amazonses.com ~all'],
                    ['key' => 'dkim', 'type' => 'TXT', 'name' => 'resend._domainkey', 'value' => 'p=MIGfMA0GCSqGSIb3DQEB'],
                ],
                'checks' => ['spf' => true, 'dkim' => true, 'dmarc' => false],
            ],
        ]);

        // DKIM missing → fails.
        $this->dns->set('send.customer.com', DNS_TXT, ['v=spf1 include:amazonses.com ~all']);
        $this->verify($host)->assertSessionHasErrors('host');
        $this->assertSame(['DKIM for customer.com'], $host->fresh()->dns_check['failed']);

        // DKIM present, DMARC still missing → passes (DMARC is advisory).
        $this->dns->set('resend._domainkey.customer.com', DNS_TXT, ['p=MIGfMA0GCSqGSIb3DQEB']);
        $this->verify($host)->assertSessionHasNoErrors();
        $host->refresh();
        $this->assertSame('active', $host->status);
        $dmarc = collect($host->dns_check['checks'])->firstWhere('key', 'dmarc:customer.com');
        $this->assertFalse($dmarc['pass']);
        $this->assertFalse($dmarc['required']);
    }

    public function test_platform_subdomain_is_auto_active_without_routing_lookup(): void
    {
        $org = Organization::factory()->create();
        $host = OrganizationHost::query()->create([
            'organization_id' => $org->id, 'host' => 'acme.maildesk.test', 'subdomain' => 'acme',
            'status' => 'provisioning', 'ssl' => false, 'is_custom' => false,
        ]);

        $this->verify($host)->assertSessionHasNoErrors();
        $this->assertSame('active', $host->fresh()->status);
        $this->assertSame([], array_filter($this->dns->queried, fn ($q) => $q[0] === 'acme.maildesk.test'));
    }

    public function test_non_admin_cannot_verify(): void
    {
        $host = $this->customHost();

        $this->actingAs(User::factory()->create())->post(route('admin.subdomains.verify', $host))->assertForbidden();
    }
}
