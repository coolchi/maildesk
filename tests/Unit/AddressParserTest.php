<?php

namespace Tests\Unit;

use App\Mail\Inbound\AddressParser;
use PHPUnit\Framework\TestCase;

class AddressParserTest extends TestCase
{
    public function test_message_ids_parses_space_separated_string(): void
    {
        $result = AddressParser::messageIds('<msg1@example.com> <msg2@example.com>');

        $this->assertCount(2, $result);
        $this->assertContains('<msg1@example.com>', $result);
        $this->assertContains('<msg2@example.com>', $result);
    }

    public function test_message_ids_parses_array(): void
    {
        $result = AddressParser::messageIds(['<msg1@example.com>', '<msg2@example.com>']);

        $this->assertCount(2, $result);
        $this->assertContains('<msg1@example.com>', $result);
        $this->assertContains('<msg2@example.com>', $result);
    }

    public function test_message_ids_parses_json_string(): void
    {
        $result = AddressParser::messageIds('["<msg1@example.com>","<msg2@example.com>"]');

        $this->assertCount(2, $result);
        $this->assertContains('<msg1@example.com>', $result);
        $this->assertContains('<msg2@example.com>', $result);
    }

    public function test_message_ids_handles_corrupted_json_in_angle_brackets(): void
    {
        $result = AddressParser::messageIds('<["<msg1@example.com>","<msg2@example.com>"]>');

        $this->assertCount(2, $result);
        $this->assertContains('<msg1@example.com>', $result);
        $this->assertContains('<msg2@example.com>', $result);
    }

    public function test_message_ids_handles_array_with_corrupted_entry(): void
    {
        $result = AddressParser::messageIds(['<["<msg1@example.com>","<msg2@example.com>"]>']);

        $this->assertCount(2, $result);
        $this->assertContains('<msg1@example.com>', $result);
        $this->assertContains('<msg2@example.com>', $result);
    }

    public function test_message_ids_handles_nested_json_in_array(): void
    {
        $result = AddressParser::messageIds(['["<msg1@example.com>","<msg2@example.com>"]']);

        $this->assertCount(2, $result);
        $this->assertContains('<msg1@example.com>', $result);
        $this->assertContains('<msg2@example.com>', $result);
    }

    public function test_message_ids_handles_empty_input(): void
    {
        $this->assertSame([], AddressParser::messageIds(null));
        $this->assertSame([], AddressParser::messageIds(''));
        $this->assertSame([], AddressParser::messageIds([]));
    }

    public function test_message_ids_removes_duplicates(): void
    {
        $result = AddressParser::messageIds(['<msg1@example.com>', '<msg1@example.com>', '<msg2@example.com>']);

        $this->assertCount(2, $result);
    }

    public function test_message_ids_skips_invalid_ids(): void
    {
        $result = AddressParser::messageIds(['<valid@example.com>', 'invalid-no-at-sign', '', '<another@test.com>']);

        $this->assertCount(2, $result);
        $this->assertContains('<valid@example.com>', $result);
        $this->assertContains('<another@test.com>', $result);
    }

    public function test_message_ids_normalizes_angle_brackets(): void
    {
        $result = AddressParser::messageIds(['msg@example.com', '<msg2@example.com>']);

        $this->assertCount(2, $result);
        $this->assertContains('<msg@example.com>', $result);
        $this->assertContains('<msg2@example.com>', $result);
    }

    public function test_message_id_normalizes_single_value(): void
    {
        $this->assertSame('<msg@example.com>', AddressParser::messageId('msg@example.com'));
        $this->assertSame('<msg@example.com>', AddressParser::messageId('<msg@example.com>'));
        $this->assertSame('<msg@example.com>', AddressParser::messageId(' <msg@example.com> '));
    }

    public function test_message_id_returns_null_for_empty(): void
    {
        $this->assertNull(AddressParser::messageId(null));
        $this->assertNull(AddressParser::messageId(''));
        $this->assertNull(AddressParser::messageId('   '));
    }
}
