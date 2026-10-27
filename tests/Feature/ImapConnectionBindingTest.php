<?php

namespace Tests\Feature;

use App\Jobs\PushInboundToStalwart;
use App\Jobs\PushOutboundToStalwart;
use App\Services\Imap\ImapConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class ImapConnectionBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_imap_connection_is_resolvable_from_container(): void
    {
        config([
            'maildesk.stalwart.clients.imap.host' => 'mail.example.com',
            'maildesk.stalwart.clients.imap.port' => 993,
            'maildesk.stalwart.clients.imap.encryption' => 'ssl',
        ]);

        $connection = app(ImapConnection::class);

        $this->assertInstanceOf(ImapConnection::class, $connection);
        $this->assertSame('{mail.example.com:993/imap/ssl/novalidate-cert}INBOX', $connection->mailboxPath('INBOX'));
    }

    public function test_imap_connection_uses_config_values(): void
    {
        config([
            'maildesk.stalwart.clients.imap.host' => 'custom.stalwart.test',
            'maildesk.stalwart.clients.imap.port' => 143,
            'maildesk.stalwart.clients.imap.encryption' => 'tls',
        ]);

        $connection = app(ImapConnection::class);

        $this->assertSame('{custom.stalwart.test:143/imap/tls/novalidate-cert}Sent', $connection->mailboxPath('Sent'));
    }

    public function test_imap_connection_defaults_when_config_missing(): void
    {
        config(['maildesk.stalwart.clients.imap' => []]);

        $connection = app(ImapConnection::class);

        $this->assertSame('{127.0.0.1:993/imap/ssl/novalidate-cert}INBOX', $connection->mailboxPath('INBOX'));
    }

    public function test_push_inbound_job_can_resolve_dependencies(): void
    {
        config([
            'maildesk.stalwart.clients.imap.host' => 'mail.test',
            'maildesk.stalwart.clients.imap.port' => 993,
        ]);

        $job = new PushInboundToStalwart(1);

        $method = new ReflectionMethod($job, 'handle');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame(ImapConnection::class, $params[0]->getType()->getName());

        $resolved = app(ImapConnection::class);
        $this->assertInstanceOf(ImapConnection::class, $resolved);
    }

    public function test_push_outbound_job_can_resolve_dependencies(): void
    {
        config([
            'maildesk.stalwart.clients.imap.host' => 'mail.test',
            'maildesk.stalwart.clients.imap.port' => 993,
        ]);

        $job = new PushOutboundToStalwart(1);

        $method = new ReflectionMethod($job, 'handle');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame(ImapConnection::class, $params[0]->getType()->getName());

        $resolved = app(ImapConnection::class);
        $this->assertInstanceOf(ImapConnection::class, $resolved);
    }

    public function test_each_resolution_returns_fresh_instance(): void
    {
        config([
            'maildesk.stalwart.clients.imap.host' => 'mail.test',
        ]);

        $first = app(ImapConnection::class);
        $second = app(ImapConnection::class);

        $this->assertNotSame($first, $second);
    }
}
