<?php

namespace Tests\Feature;

use App\Services\Domains\DnsResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DnsResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['maildesk.domains.resolver' => 'doh', 'maildesk.domains.doh_url' => 'https://1.1.1.1/dns-query']);
    }

    public function test_txt_records_come_from_the_public_resolver_and_join_split_strings(): void
    {
        Http::fake(['1.1.1.1/*' => Http::response([
            'Status' => 0,
            'Answer' => [
                ['name' => 'send.in.desk.ng', 'type' => 5, 'data' => 'alias.example.'],
                ['name' => 'send.in.desk.ng', 'type' => 16, 'data' => '"v=spf1 include:amazonses.com ~all"'],
                ['name' => 'send.in.desk.ng', 'type' => 16, 'data' => '"p=abc" "def"'],
            ],
        ])]);

        $txt = (new DnsResolver)->txt('send.in.desk.ng');

        $this->assertSame(['v=spf1 include:amazonses.com ~all', 'p=abcdef'], $txt);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://1.1.1.1/dns-query')
            && $r['name'] === 'send.in.desk.ng' && $r['type'] === 'TXT'
            && $r->hasHeader('Accept', 'application/dns-json'));
    }

    public function test_txt_decimal_byte_escapes_stay_valid_utf8(): void
    {
        Http::fake(['1.1.1.1/*' => Http::response([
            'Status' => 0,
            'Answer' => [[
                'name' => 'resend._domainkey.maildesk.ng',
                'type' => 16,
                'data' => '"p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQMockDkimKey\\226\\128\\166"',
            ]],
        ])]);

        $txt = (new DnsResolver)->txt('resend._domainkey.maildesk.ng');

        $this->assertSame(['p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQMockDkimKey…'], $txt);
        $this->assertTrue(mb_check_encoding($txt[0], 'UTF-8'));
    }

    public function test_mx_records_are_parsed_from_the_public_resolver(): void
    {
        Http::fake(['1.1.1.1/*' => Http::response([
            'Status' => 0,
            'Answer' => [['name' => 'send.in.desk.ng', 'type' => 15, 'data' => '10 Feedback-SMTP.eu-west-1.amazonses.com.']],
        ])]);

        $this->assertSame(
            [['host' => 'feedback-smtp.eu-west-1.amazonses.com', 'priority' => 10]],
            (new DnsResolver)->mx('send.in.desk.ng'),
        );
    }

    public function test_nxdomain_from_the_public_resolver_means_no_records(): void
    {
        Http::fake(['1.1.1.1/*' => Http::response(['Status' => 3])]);

        $this->assertSame([], (new DnsResolver)->mx('missing.desk.ng'));
    }

    public function test_it_falls_back_to_the_system_resolver_when_the_public_one_fails(): void
    {
        Http::fake(['1.1.1.1/*' => fn () => throw new ConnectionException('unreachable')]);

        $resolver = new class extends DnsResolver
        {
            public ?array $seen = null;

            protected function queryPublic(string $host, string $type, int $typeCode): ?array
            {
                return $this->seen = parent::queryPublic($host, $type, $typeCode);
            }
        };

        $resolver->txt('invalid.test');

        $this->assertNull($resolver->seen);
    }

    public function test_servfail_is_treated_as_inconclusive(): void
    {
        Http::fake(['1.1.1.1/*' => Http::response(['Status' => 2])]);

        $resolver = new class extends DnsResolver
        {
            public function probe(): ?array
            {
                return $this->queryPublic('x.desk.ng', 'TXT', 16);
            }
        };

        $this->assertNull($resolver->probe());
    }

    public function test_system_mode_skips_the_public_resolver(): void
    {
        config(['maildesk.domains.resolver' => 'system']);
        Http::fake();

        (new DnsResolver)->txt('invalid.test');

        Http::assertNothingSent();
    }
}
