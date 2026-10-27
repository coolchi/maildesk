<?php

namespace Tests\Feature;

use App\Services\Imap\ImapSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class ImapSyncSkipMailDeskMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected ImapSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ImapSyncService::class);
    }

    public function test_is_mail_desk_message_returns_true_for_marker_header(): void
    {
        $method = new ReflectionMethod($this->service, 'isMailDeskMessage');

        $raw = "From: test@example.com\r\n".
            "To: inbox@acme.test\r\n".
            "Subject: Test\r\n".
            "X-MailDesk-Message-Id: 550e8400-e29b-41d4-a716-446655440000\r\n".
            "\r\n".
            'Body content';

        $this->assertTrue($method->invoke($this->service, $raw));
    }

    public function test_is_mail_desk_message_returns_false_for_regular_message(): void
    {
        $method = new ReflectionMethod($this->service, 'isMailDeskMessage');

        $raw = "From: external@example.com\r\n".
            "To: inbox@acme.test\r\n".
            "Subject: External message\r\n".
            "Message-ID: <abc@external.com>\r\n".
            "\r\n".
            'This is an external message';

        $this->assertFalse($method->invoke($this->service, $raw));
    }

    public function test_is_mail_desk_message_is_case_insensitive(): void
    {
        $method = new ReflectionMethod($this->service, 'isMailDeskMessage');

        $raw = "From: test@example.com\r\n".
            "x-maildesk-message-id: some-uuid\r\n".
            "\r\n".
            'Body';

        $this->assertTrue($method->invoke($this->service, $raw));
    }

    public function test_is_mail_desk_message_handles_various_whitespace(): void
    {
        $method = new ReflectionMethod($this->service, 'isMailDeskMessage');

        $raw = "From: test@example.com\r\n".
            "X-MailDesk-Message-Id:   uuid-with-spaces  \r\n".
            "\r\n".
            'Body';

        $this->assertTrue($method->invoke($this->service, $raw));
    }

    public function test_does_not_match_partial_header_names(): void
    {
        $method = new ReflectionMethod($this->service, 'isMailDeskMessage');

        $raw = "From: test@example.com\r\n".
            "X-Some-Other-MailDesk-Message-Id: value\r\n".
            "\r\n".
            'Body';

        $this->assertFalse($method->invoke($this->service, $raw));
    }
}
