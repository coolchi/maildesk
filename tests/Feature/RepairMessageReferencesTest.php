<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Thread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairMessageReferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function createMessageWithReferences(array $references): Message
    {
        $thread = Thread::factory()->create();

        return Message::factory()->create([
            'organization_id' => $thread->organization_id,
            'thread_id' => $thread->id,
            'references' => $references,
        ]);
    }

    public function test_repairs_json_wrapped_in_angle_brackets(): void
    {
        $message = $this->createMessageWithReferences([
            '<["<msg1@example.com>","<msg2@example.com>"]>',
        ]);

        $this->artisan('mail:repair-references')
            ->assertSuccessful()
            ->expectsOutputToContain('Repaired 1 messages');

        $message->refresh();
        $this->assertCount(2, $message->references);
        $this->assertContains('<msg1@example.com>', $message->references);
        $this->assertContains('<msg2@example.com>', $message->references);
    }

    public function test_repairs_json_string_in_array(): void
    {
        $message = $this->createMessageWithReferences([
            '["<msg1@example.com>","<msg2@example.com>"]',
        ]);

        $this->artisan('mail:repair-references')
            ->assertSuccessful()
            ->expectsOutputToContain('Repaired 1 messages');

        $message->refresh();
        $this->assertCount(2, $message->references);
        $this->assertContains('<msg1@example.com>', $message->references);
        $this->assertContains('<msg2@example.com>', $message->references);
    }

    public function test_skips_valid_references(): void
    {
        $message = $this->createMessageWithReferences([
            '<msg1@example.com>',
            '<msg2@example.com>',
        ]);

        $this->artisan('mail:repair-references')
            ->assertSuccessful()
            ->expectsOutputToContain('Repaired 0 messages');

        $message->refresh();
        $this->assertCount(2, $message->references);
    }

    public function test_dry_run_does_not_modify(): void
    {
        $original = ['<["<msg1@example.com>","<msg2@example.com>"]>'];
        $message = $this->createMessageWithReferences($original);

        $this->artisan('mail:repair-references --dry-run')
            ->assertSuccessful()
            ->expectsOutputToContain('Would repair 1 messages');

        $message->refresh();
        $this->assertSame($original, $message->references);
    }
}
