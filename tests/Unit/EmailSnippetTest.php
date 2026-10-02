<?php

namespace Tests\Unit;

use App\Support\EmailSnippet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailSnippetTest extends TestCase
{
    public function test_plain_body_becomes_a_short_preview(): void
    {
        $this->assertSame(
            'Hi, my order has not arrived. Thanks,',
            EmailSnippet::from("Hi, my order has not arrived.\n\nThanks,"),
        );
    }

    public function test_intro_before_a_forward_is_used_as_the_preview(): void
    {
        $body = <<<'TEXT'
Please handle this for the customer.

---------- Forwarded message ----------
From: Jane <jane@example.com>
Date: Fri, 2 Oct 2026
Subject: Order issue
To: support@acme.test

Hi, my order has not arrived.
TEXT;

        $this->assertSame('Please handle this for the customer.', EmailSnippet::from($body));
    }

    public function test_forward_only_mail_shows_the_real_first_line(): void
    {
        $body = <<<'TEXT'
---------- Forwarded message ----------
From: Jane <jane@example.com>
Date: Fri, 2 Oct 2026
Subject: Order issue
To: support@acme.test

Hi, my order has not arrived.
Please help.
TEXT;

        $this->assertSame('Hi, my order has not arrived. Please help.', EmailSnippet::from($body));
    }

    public function test_begin_forwarded_message_marker_is_skipped(): void
    {
        $body = <<<'TEXT'
Begin forwarded message:

From: Ada <ada@example.com>
Subject: Hello

The invoice is attached.
TEXT;

        $this->assertSame('The invoice is attached.', EmailSnippet::from($body));
    }

    public function test_original_message_marker_is_skipped(): void
    {
        $body = <<<'TEXT'
-----Original Message-----
From: Ada <ada@example.com>
Sent: Friday, October 2, 2026 9:00 AM
To: Support
Subject: Hello

Can we reschedule?
TEXT;

        $this->assertSame('Can we reschedule?', EmailSnippet::from($body));
    }

    public function test_html_only_forward_uses_the_visible_body(): void
    {
        $html = '<div>---------- Forwarded message ---------</div>'
            .'<div>From: Jane &lt;jane@example.com&gt;</div>'
            .'<div>Subject: Hello</div><br>'
            .'<div>The real first line is here.</div>';

        $this->assertSame('The real first line is here.', EmailSnippet::from(null, $html));
    }

    public function test_display_cleans_a_stored_forwarded_snippet(): void
    {
        $stored = '---------- Forwarded message ---------- From: Jane <jane@example.com> Date: Fri Subject: Order issue To: support@acme.test Hi, my order has not arrived.';

        $this->assertSame('Hi, my order has not arrived.', EmailSnippet::display($stored));
    }

    #[DataProvider('emptyBodies')]
    public function test_empty_bodies_return_an_empty_snippet(?string $text, ?string $html): void
    {
        $this->assertSame('', EmailSnippet::from($text, $html));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function emptyBodies(): array
    {
        return [
            'nulls' => [null, null],
            'blank text' => ['   ', null],
            'empty html' => [null, '<div></div>'],
        ];
    }
}
