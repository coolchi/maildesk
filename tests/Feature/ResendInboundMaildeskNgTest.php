<?php

namespace Tests\Feature;

use App\Jobs\ClassifyInboundMessage;
use App\Jobs\DispatchWebhook;
use App\Jobs\ProcessResendInboundEmail;
use App\Models\Attachment;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Tests Resend inbound email processing for the maildesk.ng production domain.
 *
 * These tests verify realistic Resend `email.received` webhook payloads:
 * - New messages with received_for (envelope recipients)
 * - Replies to existing threads (including to emails we sent)
 * - Multiple recipients (To, Cc, received_for)
 * - Attachments downloaded from Resend's API
 *
 * The architecture is:
 * 1. Webhook verifies signature, dedupes, dispatches ProcessResendInboundEmail job, returns 202
 * 2. Job fetches content from Resend API, routes using received_for/to/cc, creates message
 */
class ResendInboundMaildeskNgTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_'.'dGVzdC1zZWNyZXQtZm9yLW1haWxkZXNr';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([DispatchWebhook::class, ProcessResendInboundEmail::class, ClassifyInboundMessage::class]);
        Storage::fake('local');
        Cache::flush();
        config([
            'services.resend.key' => 're_test_production_key',
            'maildesk.inbound.resend_webhook_secret' => self::SECRET,
        ]);
    }

    /**
     * Create a signed Resend webhook request.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postResend(array $payload, ?int $timestamp = null): TestResponse
    {
        $body = json_encode($payload);
        $id = 'msg_'.uniqid();
        $timestamp ??= time();
        $key = base64_decode(substr(self::SECRET, 6));
        $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

        return $this->call('POST', '/api/v1/inbound/resend', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_SVIX_ID' => $id,
            'HTTP_SVIX_TIMESTAMP' => (string) $timestamp,
            'HTTP_SVIX_SIGNATURE' => "v1,{$signature}",
        ], $body);
    }

    /**
     * Build a realistic Resend email.received webhook payload.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function resendPayload(array $overrides = []): array
    {
        return [
            'type' => 'email.received',
            'created_at' => now()->toIso8601String(),
            'data' => array_merge([
                'email_id' => 're_'.uniqid(),
                'from' => 'Customer Name <customer@example.com>',
                'to' => ['support@maildesk.ng'],
                'subject' => 'Test subject',
            ], $overrides),
        ];
    }

    /**
     * Post webhook and run the processing job to complete the email flow.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postAndProcess(array $payload): TestResponse
    {
        $response = $this->postResend($payload);
        $response->assertStatus(202)->assertJsonPath('status', 'accepted');

        $emailId = $payload['data']['email_id'];
        (new ProcessResendInboundEmail($emailId, $payload['data']))->handle();

        return $response;
    }

    public function test_new_email_to_mailbox_on_maildesk_ng_creates_thread(): void
    {
        $org = Organization::factory()->create();
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'support@maildesk.ng',
        ]);

        Http::fake([
            'api.resend.com/emails/receiving/re_maildesk_new_1' => Http::response([
                'object' => 'email',
                'html' => '<p>Hello, I need help with my account.</p>',
                'text' => 'Hello, I need help with my account.',
                'message_id' => '<abc123@mail.example.com>',
                'received_for' => ['support@maildesk.ng'],
            ]),
            'api.resend.com/emails/receiving/re_maildesk_new_1/attachments' => Http::response(['data' => []]),
        ]);

        $this->postAndProcess($this->resendPayload([
            'email_id' => 're_maildesk_new_1',
            'from' => 'Jane Doe <jane@example.com>',
            'to' => ['support@maildesk.ng'],
            'subject' => 'Help with my account',
        ]));

        $message = Message::query()->firstOrFail();
        $this->assertSame($org->id, $message->organization_id);
        $this->assertSame($mailbox->id, $message->mailbox_id);
        $this->assertSame('inbound', $message->direction);
        $this->assertSame('jane@example.com', $message->from_email);
        $this->assertSame('Jane Doe', $message->from_name);
        $this->assertSame('Help with my account', $message->subject);
        $this->assertSame('<abc123@mail.example.com>', $message->message_id_header);

        $thread = $message->thread;
        $this->assertSame($mailbox->id, $thread->mailbox_id);
        $this->assertFalse($thread->is_read);
        $this->assertSame(1, $thread->message_count);
    }

    public function test_email_routed_via_received_for_when_to_header_differs(): void
    {
        $org = Organization::factory()->create();
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'support@maildesk.ng',
        ]);

        Http::fake([
            'api.resend.com/emails/receiving/re_received_for_routing' => Http::response([
                'object' => 'email',
                'text' => 'Forwarded email',
                'received_for' => ['support@maildesk.ng'],
            ]),
            'api.resend.com/emails/receiving/re_received_for_routing/attachments' => Http::response(['data' => []]),
        ]);

        $this->postAndProcess($this->resendPayload([
            'email_id' => 're_received_for_routing',
            'from' => 'sender@external.com',
            'to' => ['original-recipient@elsewhere.com'],
            'cc' => [],
            'subject' => 'Forwarded via alias',
        ]));

        $message = Message::query()->firstOrFail();
        $this->assertSame($mailbox->id, $message->mailbox_id);
        $this->assertSame($org->id, $message->organization_id);
    }

    public function test_reply_threads_onto_existing_conversation(): void
    {
        $org = Organization::factory()->create();
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'support@maildesk.ng',
        ]);

        Http::fake([
            'api.resend.com/emails/receiving/re_first_msg' => Http::response([
                'text' => 'First message body',
                'message_id' => '<first@example.com>',
                'received_for' => ['support@maildesk.ng'],
            ]),
            'api.resend.com/emails/receiving/re_first_msg/attachments' => Http::response(['data' => []]),
            'api.resend.com/emails/receiving/re_reply_msg' => Http::response([
                'text' => 'Reply body',
                'message_id' => '<reply@example.com>',
                'in_reply_to' => '<first@example.com>',
                'references' => '<first@example.com>',
                'received_for' => ['support@maildesk.ng'],
            ]),
            'api.resend.com/emails/receiving/re_reply_msg/attachments' => Http::response(['data' => []]),
        ]);

        $this->postAndProcess($this->resendPayload([
            'email_id' => 're_first_msg',
            'from' => 'customer@example.com',
            'to' => ['support@maildesk.ng'],
            'subject' => 'Original question',
        ]));

        $firstThread = Thread::query()->firstOrFail();

        $this->postAndProcess($this->resendPayload([
            'email_id' => 're_reply_msg',
            'from' => 'customer@example.com',
            'to' => ['support@maildesk.ng'],
            'subject' => 'Re: Original question',
        ]));

        $this->assertSame(2, $firstThread->fresh()->message_count);
        $this->assertSame(1, Thread::query()->count());
    }

    public function test_customer_reply_to_email_we_sent_threads_correctly(): void
    {
        $user = User::factory()->create();
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);
        $org = Organization::factory()->create([
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);
        Domain::factory()->verified()->create(['organization_id' => $org->id, 'name' => 'maildesk.ng']);
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'support@maildesk.ng',
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('emails.store'), [
                'from' => 'support@maildesk.ng',
                'to' => 'customer@example.com',
                'subject' => 'Your ticket #12345',
                'html' => '<p>We received your request and will respond shortly.</p>',
            ])
            ->assertRedirect();

        $outbound = Message::query()->where('direction', 'outbound')->firstOrFail();
        $outboundMessageId = $outbound->message_id_header;

        $this->assertNotNull($outboundMessageId);
        $this->assertStringContainsString('@maildesk.ng>', $outboundMessageId);

        Http::fake([
            'api.resend.com/emails/receiving/re_customer_reply' => Http::response([
                'text' => 'Thank you! Here is more info about my issue...',
                'message_id' => '<customer-reply@example.com>',
                'in_reply_to' => $outboundMessageId,
                'references' => $outboundMessageId,
                'received_for' => ['support@maildesk.ng'],
            ]),
            'api.resend.com/emails/receiving/re_customer_reply/attachments' => Http::response(['data' => []]),
        ]);

        $this->postAndProcess($this->resendPayload([
            'email_id' => 're_customer_reply',
            'from' => 'customer@example.com',
            'to' => ['support@maildesk.ng'],
            'subject' => 'Re: Your ticket #12345',
        ]));

        $reply = Message::query()->where('direction', 'inbound')->firstOrFail();
        $this->assertSame($outbound->thread_id, $reply->thread_id);

        $thread = Thread::query()->findOrFail($outbound->thread_id);
        $this->assertSame(2, $thread->message_count);
        $this->assertFalse($thread->is_read);
    }

    public function test_email_with_multiple_recipients_to_cc_and_received_for(): void
    {
        $org = Organization::factory()->create();
        $primary = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'support@maildesk.ng',
        ]);
        $secondary = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'sales@maildesk.ng',
        ]);

        Http::fake([
            'api.resend.com/emails/receiving/re_multi_recipient' => Http::response([
                'text' => 'Multi-recipient email',
                'message_id' => '<multi@example.com>',
                'received_for' => ['support@maildesk.ng', 'sales@maildesk.ng'],
            ]),
            'api.resend.com/emails/receiving/re_multi_recipient/attachments' => Http::response(['data' => []]),
        ]);

        $this->postAndProcess($this->resendPayload([
            'email_id' => 're_multi_recipient',
            'from' => 'sender@external.com',
            'to' => ['support@maildesk.ng'],
            'cc' => ['sales@maildesk.ng'],
            'subject' => 'Question for support and sales',
        ]));

        $message = Message::query()->firstOrFail();
        $this->assertSame($primary->id, $message->mailbox_id);
        $this->assertSame(['support@maildesk.ng'], $message->to);
        $this->assertSame(['sales@maildesk.ng'], $message->cc);

        Queue::assertPushed(DispatchWebhook::class, fn (DispatchWebhook $job) => $job->event === 'email.received');
    }

    public function test_email_with_attachment_downloads_from_resend_api(): void
    {
        $org = Organization::factory()->create();
        Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'support@maildesk.ng',
        ]);

        $attachmentContent = '%PDF-1.7 test invoice content';

        Http::fake([
            'api.resend.com/emails/receiving/re_with_attachment' => Http::response([
                'object' => 'email',
                'text' => 'Please see the attached invoice.',
                'message_id' => '<attachment-email@example.com>',
                'received_for' => ['support@maildesk.ng'],
            ]),
            'api.resend.com/emails/receiving/re_with_attachment/attachments' => Http::response([
                'object' => 'list',
                'data' => [
                    [
                        'id' => 'att_invoice',
                        'filename' => 'invoice.pdf',
                        'content_type' => 'application/pdf',
                        'download_url' => 'https://files.resend.test/att_invoice',
                    ],
                ],
            ]),
            'files.resend.test/att_invoice' => Http::response($attachmentContent),
        ]);

        $this->postAndProcess($this->resendPayload([
            'email_id' => 're_with_attachment',
            'from' => 'vendor@supplier.com',
            'to' => ['support@maildesk.ng'],
            'subject' => 'Invoice #9876',
            'attachments' => [
                ['id' => 'att_invoice', 'filename' => 'invoice.pdf', 'content_type' => 'application/pdf'],
            ],
        ]));

        $attachment = Attachment::query()->sole();
        $this->assertSame('invoice.pdf', $attachment->filename);
        $this->assertSame('application/pdf', $attachment->content_type);
        $this->assertSame(strlen($attachmentContent), $attachment->size);
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame($attachmentContent, Storage::disk('local')->get($attachment->path));
    }

    public function test_domain_catch_all_routes_email_to_organization(): void
    {
        $org = Organization::factory()->create();
        Domain::factory()->verified()->create([
            'organization_id' => $org->id,
            'name' => 'maildesk.ng',
        ]);

        Http::fake([
            'api.resend.com/emails/receiving/re_catchall' => Http::response([
                'text' => 'Email to unknown alias',
                'message_id' => '<catchall@example.com>',
                'received_for' => ['unknown-alias@maildesk.ng'],
            ]),
            'api.resend.com/emails/receiving/re_catchall/attachments' => Http::response(['data' => []]),
        ]);

        $this->postAndProcess($this->resendPayload([
            'email_id' => 're_catchall',
            'from' => 'external@example.com',
            'to' => ['unknown-alias@maildesk.ng'],
            'subject' => 'Catch-all test',
        ]));

        $message = Message::query()->firstOrFail();
        $this->assertSame($org->id, $message->organization_id);
        $this->assertNull($message->mailbox_id);
    }

    public function test_reply_with_different_subject_still_threads_by_message_id(): void
    {
        $org = Organization::factory()->create();
        Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'support@maildesk.ng',
        ]);

        Http::fake([
            'api.resend.com/emails/receiving/re_thread_original' => Http::response([
                'text' => 'Original',
                'message_id' => '<original-thread@example.com>',
                'received_for' => ['support@maildesk.ng'],
            ]),
            'api.resend.com/emails/receiving/re_thread_original/attachments' => Http::response(['data' => []]),
            'api.resend.com/emails/receiving/re_thread_reply' => Http::response([
                'text' => 'Still the same conversation',
                'message_id' => '<different-subject@example.com>',
                'in_reply_to' => '<original-thread@example.com>',
                'received_for' => ['support@maildesk.ng'],
            ]),
            'api.resend.com/emails/receiving/re_thread_reply/attachments' => Http::response(['data' => []]),
        ]);

        $this->postAndProcess($this->resendPayload([
            'email_id' => 're_thread_original',
            'from' => 'customer@example.com',
            'to' => ['support@maildesk.ng'],
            'subject' => 'Help needed',
        ]));

        $firstThread = Thread::query()->firstOrFail();

        $this->postAndProcess($this->resendPayload([
            'email_id' => 're_thread_reply',
            'from' => 'customer@example.com',
            'to' => ['support@maildesk.ng'],
            'subject' => 'Actually, a different topic',
        ]));

        $this->assertSame(2, $firstThread->fresh()->message_count);
        $this->assertSame(1, Thread::query()->count());
    }

    public function test_webhook_payload_without_body_fetches_from_receiving_api(): void
    {
        $org = Organization::factory()->create();
        Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'support@maildesk.ng',
        ]);

        Http::fake([
            'api.resend.com/emails/receiving/re_metadata_only' => Http::response([
                'object' => 'email',
                'html' => '<p>Full body from API</p>',
                'text' => 'Full body from API',
                'message_id' => '<fetched@example.com>',
                'in_reply_to' => null,
                'references' => [],
                'received_for' => ['support@maildesk.ng'],
                'headers' => [
                    ['name' => 'X-Custom-Header', 'value' => 'test-value'],
                ],
            ]),
            'api.resend.com/emails/receiving/re_metadata_only/attachments' => Http::response(['data' => []]),
        ]);

        $this->postAndProcess($this->resendPayload([
            'email_id' => 're_metadata_only',
            'from' => 'sender@example.com',
            'to' => ['support@maildesk.ng'],
            'subject' => 'Metadata only webhook',
        ]));

        $message = Message::query()->firstOrFail();
        $this->assertSame('Full body from API', $message->text_body);
        $this->assertSame('<p>Full body from API</p>', $message->html_body);
        $this->assertSame('<fetched@example.com>', $message->message_id_header);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'emails/receiving/re_metadata_only')
            && $request->hasHeader('Authorization', 'Bearer re_test_production_key'));
    }

    public function test_threading_via_references_header_when_in_reply_to_missing(): void
    {
        $org = Organization::factory()->create();
        Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'support@maildesk.ng',
        ]);

        Http::fake([
            'api.resend.com/emails/receiving/re_ref_first' => Http::response([
                'text' => 'First',
                'message_id' => '<ref-first@example.com>',
                'received_for' => ['support@maildesk.ng'],
            ]),
            'api.resend.com/emails/receiving/re_ref_first/attachments' => Http::response(['data' => []]),
            'api.resend.com/emails/receiving/re_ref_second' => Http::response([
                'text' => 'Second (reply)',
                'message_id' => '<ref-second@example.com>',
                'in_reply_to' => '<ref-first@example.com>',
                'received_for' => ['support@maildesk.ng'],
            ]),
            'api.resend.com/emails/receiving/re_ref_second/attachments' => Http::response(['data' => []]),
            'api.resend.com/emails/receiving/re_ref_third' => Http::response([
                'text' => 'Third (references only)',
                'message_id' => '<ref-third@example.com>',
                'references' => '<ref-first@example.com> <ref-second@example.com>',
                'received_for' => ['support@maildesk.ng'],
            ]),
            'api.resend.com/emails/receiving/re_ref_third/attachments' => Http::response(['data' => []]),
        ]);

        $this->postAndProcess($this->resendPayload([
            'email_id' => 're_ref_first',
            'from' => 'customer@example.com',
            'to' => ['support@maildesk.ng'],
            'subject' => 'Reference threading test',
        ]));

        $firstThread = Thread::query()->firstOrFail();

        $this->postAndProcess($this->resendPayload([
            'email_id' => 're_ref_second',
            'from' => 'customer@example.com',
            'to' => ['support@maildesk.ng'],
            'subject' => 'Re: Reference threading test',
        ]));

        $this->postAndProcess($this->resendPayload([
            'email_id' => 're_ref_third',
            'from' => 'customer@example.com',
            'to' => ['support@maildesk.ng'],
            'subject' => 'Re: Re: Reference threading test',
        ]));

        $this->assertSame(3, $firstThread->fresh()->message_count);
    }

    public function test_unroutable_email_is_logged_but_not_stored(): void
    {
        $org = Organization::factory()->create();
        Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'support@different-domain.com',
        ]);

        Http::fake([
            'api.resend.com/emails/receiving/re_unroutable' => Http::response([
                'text' => 'Email to unknown domain',
                'message_id' => '<unroutable@example.com>',
                'received_for' => ['random@maildesk.ng'],
            ]),
            'api.resend.com/emails/receiving/re_unroutable/attachments' => Http::response(['data' => []]),
        ]);

        $this->postAndProcess($this->resendPayload([
            'email_id' => 're_unroutable',
            'from' => 'sender@example.com',
            'to' => ['random@maildesk.ng'],
            'subject' => 'To unknown mailbox',
        ]));

        $this->assertSame(0, Message::query()->count());
    }

    public function test_webhook_returns_202_accepted_immediately(): void
    {
        $org = Organization::factory()->create();
        Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'support@maildesk.ng',
        ]);

        Http::fake();

        $response = $this->postResend($this->resendPayload([
            'email_id' => 're_quick_ack',
            'from' => 'sender@example.com',
            'to' => ['support@maildesk.ng'],
            'subject' => 'Quick acknowledgement test',
        ]));

        $response->assertStatus(202)
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('email_id', 're_quick_ack');

        $this->assertSame(0, Message::query()->count());
    }

    public function test_duplicate_webhook_is_deduplicated(): void
    {
        $org = Organization::factory()->create();
        Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'support@maildesk.ng',
        ]);

        Http::fake([
            'api.resend.com/emails/receiving/re_dupe_test' => Http::response([
                'text' => 'First delivery',
                'message_id' => '<dupe@example.com>',
                'received_for' => ['support@maildesk.ng'],
            ]),
            'api.resend.com/emails/receiving/re_dupe_test/attachments' => Http::response(['data' => []]),
        ]);

        $payload = $this->resendPayload([
            'email_id' => 're_dupe_test',
            'from' => 'sender@example.com',
            'to' => ['support@maildesk.ng'],
            'subject' => 'Duplicate test',
        ]);

        $this->postAndProcess($payload);
        $this->assertSame(1, Message::query()->count());

        $this->postResend($payload)->assertStatus(202);

        $this->assertSame(1, Message::query()->count());
    }
}
