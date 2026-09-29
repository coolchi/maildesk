<?php

namespace Tests\Feature;

use App\Jobs\DispatchWebhook;
use App\Models\Mailbox;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AutoReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([DispatchWebhook::class]);
        config([
            'maildesk.inbound.generic_secret' => 'generic-secret',
            'maildesk.fake_send' => true,
            'queue.default' => 'sync',
        ]);
    }

    /**
     * @return array{0: User, 1: Organization, 2: Mailbox}
     */
    private function workspace(): array
    {
        $user = User::factory()->create();
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'array']);
        $org = Organization::factory()->create([
            'mail_provider_id' => $provider->id,
            'default_provider' => 'array',
            'status' => 'active',
            'name' => 'Acme',
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'support@acme.test',
            'display_name' => 'Acme Support',
        ]);

        return [$user, $org, $mailbox];
    }

    /** @param array<string, mixed> $overrides */
    private function receive(array $overrides = [])
    {
        return $this->withHeader('X-MailDesk-Inbound-Secret', 'generic-secret')
            ->postJson('/api/v1/inbound/generic', array_merge([
                'from' => 'Jane Customer <jane@example.com>',
                'to' => ['support@acme.test'],
                'subject' => 'Help with my order',
                'text' => 'Hi, my order has not arrived.',
                'message_id' => '<first@example.com>',
            ], $overrides));
    }

    public function test_received_email_gets_one_acknowledgment_in_the_same_conversation(): void
    {
        [, $org] = $this->workspace();
        $org->forceFill([
            'settings' => [
                'auto_reply' => [
                    'enabled' => true,
                    'subject' => 'Re: {{subject}}',
                    'body' => "Hi {{sender_name}},\n\nWe got your note about \"{{subject}}\".",
                ],
            ],
        ])->save();

        $this->receive()->assertCreated();

        $inbound = Message::query()->where('direction', 'inbound')->first();
        $reply = Message::query()->where('direction', 'outbound')->first();

        $this->assertNotNull($inbound);
        $this->assertNotNull($reply);
        $this->assertSame($inbound->thread_id, $reply->thread_id);
        $this->assertSame(['jane@example.com'], $reply->to);
        $this->assertSame('support@acme.test', $reply->from_email);
        $this->assertSame('Re: Help with my order', $reply->subject);
        $this->assertStringContainsString('Hi Jane Customer', (string) $reply->text_body);
        $this->assertSame('auto-replied', $reply->headers['Auto-Submitted'] ?? null);
        $this->assertContains('auto-reply', $reply->tags ?? []);
        $this->assertFalse($inbound->thread->fresh()->is_read);

        $this->receive([
            'message_id' => '<second@example.com>',
            'in_reply_to' => '<first@example.com>',
            'subject' => 'Re: Help with my order',
            'text' => 'Following up.',
        ])->assertCreated();

        $this->assertSame(1, Message::query()->where('direction', 'outbound')->count());
    }

    public function test_auto_reply_stays_off_until_enabled_and_skips_automated_mail(): void
    {
        [$user, $org] = $this->workspace();

        $this->receive()->assertCreated();
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->put(route('automations.auto-reply'), [
                'enabled' => true,
                'subject' => 'We received {{subject}}',
                'body' => 'Thanks {{sender_email}}.',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('automations'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('autoReply.enabled', true)
                ->where('autoReply.subject', 'We received {{subject}}')
                ->where('autoReply.body', 'Thanks {{sender_email}}.'));

        $this->receive([
            'from' => 'Bot <bot@lists.example.com>',
            'message_id' => '<bot@example.com>',
            'subject' => 'Weekly digest',
            'headers' => ['Auto-Submitted' => 'auto-generated'],
        ])->assertCreated();

        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());

        $this->receive([
            'from' => 'Sam <sam@example.com>',
            'message_id' => '<sam@example.com>',
            'subject' => 'Question',
            'text' => 'Hello',
        ])->assertCreated();

        $reply = Message::query()->where('direction', 'outbound')->first();
        $this->assertNotNull($reply);
        $this->assertSame(['sam@example.com'], $reply->to);
        $this->assertSame('We received Question', $reply->subject);
        $this->assertStringContainsString('Thanks sam@example.com.', (string) $reply->text_body);
    }
}
