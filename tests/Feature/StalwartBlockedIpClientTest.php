<?php

namespace Tests\Feature;

use App\Services\Imap\StalwartClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StalwartBlockedIpClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'maildesk.stalwart.enabled' => true,
            'maildesk.stalwart.url' => 'http://stalwart.test',
            'maildesk.stalwart.admin_user' => 'admin',
            'maildesk.stalwart.admin_password' => 'secret',
        ]);

        Http::preventStrayRequests();
    }

    public function test_lists_blocked_ips_from_jmap(): void
    {
        Http::fake([
            'stalwart.test/jmap' => Http::response([
                'methodResponses' => [[
                    'x:BlockedIp/get',
                    [
                        'list' => [[
                            'id' => 'abc',
                            'address' => '203.0.113.10',
                            'reason' => 'authFailure',
                            'createdAt' => '2026-10-28T12:00:00Z',
                        ]],
                    ],
                    'b0',
                ]],
            ]),
        ]);

        $entries = app(StalwartClient::class)->listBlockedIps();

        $this->assertCount(1, $entries);
        $this->assertSame('abc', $entries[0]['id']);
        $this->assertSame('203.0.113.10', $entries[0]['ip']);
        $this->assertSame('authFailure', $entries[0]['reason']);
        $this->assertSame('2026-10-28T12:00:00Z', $entries[0]['created']);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/jmap')
            && $request->hasHeader('Authorization'));
    }

    public function test_falls_back_to_settings_list_when_jmap_method_is_unknown(): void
    {
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/jmap') {
                return Http::response([
                    'methodResponses' => [[
                        'error',
                        ['type' => 'unknownMethod'],
                        'b0',
                    ]],
                ]);
            }

            if ($path === '/api/settings/list') {
                return Http::response([
                    'data' => [
                        'total' => 2,
                        'items' => [
                            '203.0.113.10' => '{"reason":"authFailure","created":"2026-10-01T00:00:00Z"}',
                            'server.blocked-ip.198.51.100.20' => '',
                            'enable' => 'true',
                        ],
                    ],
                ]);
            }

            return Http::response(['unexpected' => true], 500);
        });

        $entries = app(StalwartClient::class)->listBlockedIps();
        $ips = array_column($entries, 'ip');

        $this->assertContains('203.0.113.10', $ips);
        $this->assertContains('198.51.100.20', $ips);
        $this->assertNotContains('enable', $ips);

        $auth = collect($entries)->firstWhere('ip', '203.0.113.10');
        $this->assertSame('authFailure', $auth['reason']);
        $this->assertSame('2026-10-01T00:00:00Z', $auth['created']);
    }

    public function test_unblocks_via_jmap_destroy(): void
    {
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $body = $request->data();

            if ($path === '/jmap') {
                $method = $body['methodCalls'][0][0] ?? '';
                if ($method === 'x:BlockedIp/get') {
                    return Http::response([
                        'methodResponses' => [[
                            'x:BlockedIp/get',
                            [
                                'list' => [[
                                    'id' => 'abc',
                                    'address' => '203.0.113.10',
                                    'reason' => 'authFailure',
                                ]],
                            ],
                            'b0',
                        ]],
                    ]);
                }

                if ($method === 'x:BlockedIp/set') {
                    return Http::response([
                        'methodResponses' => [[
                            'x:BlockedIp/set',
                            ['destroyed' => ['abc']],
                            'b1',
                        ]],
                    ]);
                }

                return Http::response([
                    'methodResponses' => [['error', ['type' => 'unknownMethod'], 'x']],
                ]);
            }

            if (in_array($path, ['/api/settings', '/settings'], true)) {
                return Http::response(['data' => null], 404);
            }

            if (in_array($path, ['/api/reload', '/reload'], true)) {
                return Http::response(['data' => []]);
            }

            return Http::response(['unexpected' => true], 500);
        });

        app(StalwartClient::class)->unblockIp('203.0.113.10');

        Http::assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), '/jmap')) {
                return false;
            }

            $method = $request->data()['methodCalls'][0][0] ?? '';
            $destroy = $request->data()['methodCalls'][0][1]['destroy'] ?? null;

            return $method === 'x:BlockedIp/set' && $destroy === ['abc'];
        });
    }

    public function test_unblocks_via_settings_delete_when_jmap_is_unavailable(): void
    {
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/jmap') {
                return Http::response([
                    'methodResponses' => [[
                        'error',
                        ['type' => 'unknownMethod'],
                        'b0',
                    ]],
                ]);
            }

            if ($path === '/api/settings') {
                return Http::response(['data' => null]);
            }

            if (in_array($path, ['/api/reload', '/reload'], true)) {
                return Http::response(['data' => []]);
            }

            return Http::response(['unexpected' => true], 500);
        });

        app(StalwartClient::class)->unblockIp('203.0.113.10');

        Http::assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), '/api/settings')) {
                return false;
            }

            $payload = $request->data();
            $keys = $payload[0]['keys'] ?? [];

            return ($payload[0]['type'] ?? '') === 'delete'
                && in_array('server.blocked-ip.203.0.113.10', $keys, true);
        });
    }

    public function test_find_blocked_ip_matches_the_current_address(): void
    {
        Http::fake([
            'stalwart.test/jmap' => Http::response([
                'methodResponses' => [[
                    'x:BlockedIp/get',
                    [
                        'list' => [[
                            'id' => 'net',
                            'address' => '203.0.113.0/24',
                            'reason' => 'authFailure',
                        ]],
                    ],
                    'b0',
                ]],
            ]),
        ]);

        $client = app(StalwartClient::class);

        $this->assertNotNull($client->findBlockedIp('203.0.113.10'));
        $this->assertNull($client->findBlockedIp('198.51.100.1'));
    }
}
