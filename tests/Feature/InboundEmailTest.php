<?php

namespace Tests\Feature;

use App\Jobs\ClassifyInboundMessage;
use App\Jobs\DispatchWebhook;
use App\Jobs\ProcessResendInboundEmail;
use App\Mail\Inbound\ResendInboundDriver;
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
use Tests\TestCase;

class InboundEmailTest extends TestCase
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
            'maildesk.inbound.generic_secret' => 'generic-secret',
            'maildesk.inbound.resend_webhook_secret' => self::SECRET,
            'services.resend.key' => 're_test_key',
        ]);
    }

    private function mailbox(string $email = 'support@acme.test', ?Organization $org = null): Mailbox
    {
        return Mailbox::factory()->create([
            'organization_id' => ($org ?? Organization::factory()->create())->id,
            'email' => $email,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function postGeneric(array $overrides = [], string $secret = 'generic-secret')
    {
        return $this->withHeader('X-MailDesk-Inbound-Secret', $secret)
            ->postJson('/api/v1/inbound/generic', array_merge([
                'from' => 'Jane Customer <jane@example.com>',
                'to' => ['support@acme.test'],
                'subject' => 'Help with my order',
                'text' => 'Hi, my order has not arrived.',
                'html' => '<p>Hi, my order has not arrived.</p>',
                'message_id' => '<first@example.com>',
            ], $overrides));
    }

    /** @param array<string, mixed> $payload */
    private function postResend(array $payload, ?string $secret = null, ?int $timestamp = null)
    {
        $body = json_encode($payload);
        $id = 'msg_'.uniqid();
        $timestamp ??= time();
        $key = base64_decode(substr($secret ?? self::SECRET, 6));
        $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

        return $this->call('POST', '/api/v1/inbound/resend', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_SVIX_ID' => $id,
            'HTTP_SVIX_TIMESTAMP' => (string) $timestamp,
            'HTTP_SVIX_SIGNATURE' => "v1,{$signature}",
        ], $body);
    }

    public function test_generic_inbound_creates_thread_and_message_in_the_right_tenant(): void
    {
        $mailbox = $this->mailbox();
        $other = $this->mailbox('support@other.test');

        $response = $this->postGeneric()->assertCreated()->assertJsonPath('status', 'received');

        $message = Message::query()->where('uuid', $response->json('id'))->firstOrFail();
        $this->assertSame($mailbox->organization_id, $message->organization_id);
        $this->assertSame($mailbox->id, $message->mailbox_id);
        $this->assertSame('inbound', $message->direction);
        $this->assertSame('received', $message->status);
        $this->assertSame('jane@example.com', $message->from_email);
        $this->assertSame('Jane Customer', $message->from_name);
        $this->assertSame('<first@example.com>', $message->message_id_header);
        $this->assertNotNull($message->received_at);

        $thread = $message->thread;
        $this->assertSame($mailbox->id, $thread->mailbox_id);
        $this->assertFalse($thread->is_read);
        $this->assertSame(1, $thread->message_count);
        $this->assertSame('Hi, my order has not arrived.', $thread->snippet);

        $this->assertSame(0, Thread::query()->where('organization_id', $other->organization_id)->count());
    }

    public function test_recipient_matching_is_case_insensitive_and_checks_cc(): void
    {
        $mailbox = $this->mailbox('Billing@Acme.test');

        $this->postGeneric(['to' => ['someone@elsewhere.test'], 'cc' => 'BILLING@acme.test'])->assertCreated();

        $this->assertSame(1, Message::query()->where('mailbox_id', $mailbox->id)->count());
    }

    public function test_unknown_recipient_is_acknowledged_but_not_stored(): void
    {
        $this->mailbox();

        $this->postGeneric(['to' => ['nobody@unknown.test']])
            ->assertStatus(202)
            ->assertJsonPath('status', 'unroutable');

        $this->assertSame(0, Message::query()->count());
    }

    public function test_domain_catch_all_routes_to_owning_organization(): void
    {
        $org = Organization::factory()->create();
        Domain::factory()->verified()->create(['organization_id' => $org->id, 'name' => 'acme.test']);

        $this->postGeneric(['to' => ['random-alias@acme.test']])->assertCreated();

        $message = Message::query()->firstOrFail();
        $this->assertSame($org->id, $message->organization_id);
        $this->assertNull($message->mailbox_id);
    }

    public function test_inactive_or_non_inbox_mailboxes_do_not_receive(): void
    {
        Mailbox::factory()->create(['email' => 'support@acme.test', 'inbox' => false]);

        $this->postGeneric()->assertStatus(202);
    }

    public function test_reply_is_threaded_by_in_reply_to_and_references(): void
    {
        $this->mailbox();

        $first = $this->postGeneric()->json('thread_id');

        $this->postGeneric([
            'subject' => 'Totally different subject',
            'message_id' => '<second@example.com>',
            'in_reply_to' => '<first@example.com>',
        ])->assertCreated()->assertJsonPath('thread_id', $first);

        $this->postGeneric([
            'message_id' => '<third@example.com>',
            'headers' => ['References' => '<first@example.com> <second@example.com>'],
        ])->assertCreated()->assertJsonPath('thread_id', $first);

        $thread = Thread::query()->findOrFail($first);
        $this->assertSame(3, $thread->message_count);
        $this->assertSame(1, Thread::query()->count());
    }

    public function test_customer_reply_threads_onto_outbound_email(): void
    {
        $mailbox = $this->mailbox();
        $thread = Thread::factory()->create([
            'organization_id' => $mailbox->organization_id,
            'mailbox_id' => $mailbox->id,
            'is_read' => true,
        ]);
        Message::factory()->create([
            'organization_id' => $mailbox->organization_id,
            'thread_id' => $thread->id,
            'direction' => 'outbound',
            'message_id_header' => '<outbound-1@acme.test>',
        ]);

        $this->postGeneric(['in_reply_to' => 'outbound-1@acme.test'])
            ->assertCreated()
            ->assertJsonPath('thread_id', $thread->id);

        $this->assertFalse($thread->fresh()->is_read);
    }

    public function test_re_subject_falls_back_to_same_sender_thread(): void
    {
        $this->mailbox();
        $first = $this->postGeneric()->json('thread_id');

        $this->postGeneric([
            'subject' => 'RE: Fwd: Help with my order',
            'message_id' => '<no-headers@example.com>',
        ])->assertJsonPath('thread_id', $first);

        $this->postGeneric([
            'subject' => 'Help with my order',
            'message_id' => '<fresh@example.com>',
        ])->assertCreated();

        $this->assertSame(2, Thread::query()->count());
    }

    public function test_subject_matching_requires_reply_or_forward_marker(): void
    {
        $this->mailbox();
        $first = $this->postGeneric([
            'subject' => 'Quarterly report',
            'message_id' => '<first-quarterly@example.com>',
        ])->json('thread_id');

        $second = $this->postGeneric([
            'subject' => 'Quarterly report',
            'message_id' => '<coincidence@example.com>',
        ])->json('thread_id');

        $this->assertNotSame($first, $second, 'Same subject without Re:/Fwd: should create new thread');
        $this->assertSame(2, Thread::query()->count());

        $replyResponse = $this->postGeneric([
            'subject' => 'Re: Quarterly report',
            'message_id' => '<actual-reply@example.com>',
        ]);
        $replyResponse->assertCreated();

        $this->assertSame(2, Thread::query()->count(), 'Re: subject should thread to an existing matching thread');
        $this->assertContains(
            $replyResponse->json('thread_id'),
            [$first, $second],
            'Re: subject should thread to one of the matching threads'
        );
    }

    public function test_threads_never_cross_tenants(): void
    {
        $orgA = $this->mailbox('support@a.test');
        $this->mailbox('support@b.test');

        $this->postGeneric(['to' => ['support@a.test'], 'message_id' => '<shared@example.com>']);

        $reply = $this->postGeneric([
            'to' => ['support@b.test'],
            'message_id' => '<reply@example.com>',
            'in_reply_to' => '<shared@example.com>',
        ])->assertCreated();

        $this->assertNotSame(
            Message::query()->where('message_id_header', '<shared@example.com>')->value('thread_id'),
            $reply->json('thread_id'),
        );
        $this->assertSame(1, Thread::query()->where('organization_id', $orgA->organization_id)->count());
    }

    public function test_subject_fallback_stays_within_same_mailbox(): void
    {
        $org = Organization::factory()->create();
        $mailboxA = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'sales@acme.test',
        ]);
        $mailboxB = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'support@acme.test',
        ]);

        $firstId = $this->withHeader('X-MailDesk-Inbound-Secret', 'generic-secret')
            ->postJson('/api/v1/inbound/generic', [
                'from' => 'Jane Customer <jane@example.com>',
                'to' => ['sales@acme.test'],
                'subject' => 'Order inquiry',
                'text' => 'Original message to sales',
                'message_id' => '<sales-original@example.com>',
            ])->assertCreated()->json('thread_id');

        $secondId = $this->withHeader('X-MailDesk-Inbound-Secret', 'generic-secret')
            ->postJson('/api/v1/inbound/generic', [
                'from' => 'Jane Customer <jane@example.com>',
                'to' => ['support@acme.test'],
                'subject' => 'Re: Order inquiry',
                'text' => 'Same subject to support mailbox',
                'message_id' => '<support-reply@example.com>',
            ])->assertCreated()->json('thread_id');

        $this->assertNotSame($firstId, $secondId, 'Subject fallback should not match across different mailboxes');

        $salesThread = Thread::query()->find($firstId);
        $supportThread = Thread::query()->find($secondId);

        $this->assertSame($mailboxA->id, $salesThread->mailbox_id);
        $this->assertSame($mailboxB->id, $supportThread->mailbox_id);
        $this->assertSame(2, Thread::query()->where('organization_id', $org->id)->count());
    }

    public function test_resend_duplicate_by_provider_email_id_is_deduplicated_at_webhook(): void
    {
        $this->mailbox();

        $firstResponse = $this->postResend([
            'type' => 'email.received',
            'data' => [
                'email_id' => 're_duplicate_test_123',
                'from' => 'Jane <jane@example.com>',
                'to' => ['support@acme.test'],
                'subject' => 'First delivery',
            ],
        ])->assertStatus(202);

        $secondResponse = $this->postResend([
            'type' => 'email.received',
            'data' => [
                'email_id' => 're_duplicate_test_123',
                'from' => 'Jane <jane@example.com>',
                'to' => ['support@acme.test'],
                'subject' => 'Resend retry delivery',
            ],
        ])->assertStatus(202);

        $this->assertSame($firstResponse->json('email_id'), $secondResponse->json('email_id'));

        Queue::assertPushedTimes(ProcessResendInboundEmail::class, 1);
    }

    public function test_duplicate_deliveries_are_ignored(): void
    {
        $this->mailbox();

        $id = $this->postGeneric()->assertCreated()->json('id');
        $this->postGeneric()->assertOk()->assertJsonPath('id', $id);

        $this->assertSame(1, Message::query()->count());
        $this->assertSame(1, Thread::query()->firstOrFail()->message_count);
    }

    public function test_attachments_are_stored(): void
    {
        $this->mailbox();

        $this->postGeneric(['attachments' => [[
            'filename' => '../../invoice.pdf',
            'content_type' => 'application/pdf',
            'content' => base64_encode('%PDF-fake'),
        ]]])->assertCreated();

        $attachment = Attachment::query()->firstOrFail();
        $this->assertSame('invoice.pdf', $attachment->filename);
        $this->assertSame(9, $attachment->size);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_received_email_dispatches_webhook(): void
    {
        $mailbox = $this->mailbox();

        $this->postGeneric()->assertCreated();

        Queue::assertPushed(DispatchWebhook::class, fn (DispatchWebhook $job) => $job->organizationId === $mailbox->organization_id
            && $job->event === 'email.received');
    }

    public function test_generic_endpoint_rejects_wrong_secret(): void
    {
        $this->mailbox();

        $this->postGeneric([], 'wrong')->assertUnauthorized();
        $this->assertSame(0, Message::query()->count());
    }

    public function test_unknown_driver_returns_404(): void
    {
        $this->postJson('/api/v1/inbound/sendgrid', [])->assertNotFound();
    }

    public function test_resend_webhook_with_valid_signature_dispatches_job(): void
    {
        $this->mailbox();

        Http::fake([
            'api.resend.com/emails/receiving/re_123' => Http::response([
                'text' => 'Body included',
                'message_id' => '<resend-1@example.com>',
                'received_for' => ['support@acme.test'],
            ]),
            'api.resend.com/emails/receiving/re_123/attachments' => Http::response(['data' => []]),
        ]);

        $payload = [
            'type' => 'email.received',
            'data' => [
                'email_id' => 're_123',
                'from' => 'Jane <jane@example.com>',
                'to' => ['support@acme.test'],
                'subject' => 'From Resend',
            ],
        ];

        $this->postResend($payload)->assertStatus(202)->assertJsonPath('status', 'accepted');

        (new ProcessResendInboundEmail('re_123', $payload['data']))->handle();

        $message = Message::query()->firstOrFail();
        $this->assertSame('resend', $message->provider);
        $this->assertSame('re_123', $message->provider_message_id);
        $this->assertSame('Body included', $message->text_body);
    }

    public function test_resend_webhook_returns_202_accepted_immediately(): void
    {
        $this->mailbox();
        Http::fake();

        $this->postResend([
            'type' => 'email.received',
            'data' => [
                'email_id' => 're_456',
                'from' => 'jane@example.com',
                'to' => ['support@acme.test'],
                'subject' => 'Quick ack',
            ],
        ])->assertStatus(202)->assertJsonPath('status', 'accepted');

        $this->assertSame(0, Message::query()->count());
    }

    public function test_resend_processing_job_fetches_content_and_creates_message(): void
    {
        Http::fake([
            'api.resend.com/emails/receiving/re_fetch' => Http::response([
                'html' => '<p>Fetched body</p>',
                'text' => 'Fetched body',
                'received_for' => ['support@acme.test'],
            ]),
            'api.resend.com/emails/receiving/re_fetch/attachments' => Http::response(['data' => []]),
        ]);
        $this->mailbox();

        $job = new ProcessResendInboundEmail('re_fetch', [
            'from' => 'jane@example.com',
            'to' => ['support@acme.test'],
            'subject' => 'To be fetched',
        ]);
        $job->handle();

        $message = Message::query()->firstOrFail();
        $this->assertSame('Fetched body', $message->text_body);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer re_test_key'));
    }

    public function test_resend_rejects_bad_or_stale_signatures(): void
    {
        $this->mailbox();
        $payload = ['type' => 'email.received', 'data' => ['from' => 'a@b.test', 'to' => ['support@acme.test']]];

        $this->postResend($payload, 'whsec_'.base64_encode('another-secret'))->assertUnauthorized();
        $this->postResend($payload, null, time() - 3600)->assertUnauthorized();

        $this->assertSame(0, Message::query()->count());
    }

    public function test_resend_non_inbound_events_are_ignored(): void
    {
        $this->postResend(['type' => 'email.delivered', 'data' => []])
            ->assertOk()
            ->assertJsonPath('status', 'ignored');
    }

    public function test_svix_verification_accepts_any_matching_signature_in_header(): void
    {
        $key = base64_decode(substr(self::SECRET, 6));
        $good = base64_encode(hash_hmac('sha256', 'id.'.time().'.{}', $key, true));

        $this->assertTrue(ResendInboundDriver::verifySvix(self::SECRET, 'id', (string) time(), "v1,bogus v1,{$good}", '{}'));
        $this->assertFalse(ResendInboundDriver::verifySvix(self::SECRET, 'id', (string) time(), 'v1,bogus', '{}'));
    }

    public function test_received_email_shows_in_shared_inbox_and_can_be_marked_read(): void
    {
        $user = User::factory()->create();
        $mailbox = $this->mailbox();
        $org = $mailbox->organization;
        $org->users()->attach($user->id, ['role' => 'owner']);

        $threadId = $this->postGeneric()->json('thread_id');

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('inbox'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Inbox/Index')
                ->where('threads.0.id', $threadId)
                ->where('threads.0.unread', true)
                ->where('threads.0.from', 'jane@example.com')
                ->where('threads.0.messages.0.direction', 'inbound'));

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->patchJson(route('inbox.read', $threadId), ['read' => true])
            ->assertOk()
            ->assertJson(['unread' => false, 'inbox_unread' => 0]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->patchJson(route('inbox.read', $threadId), ['read' => false])
            ->assertJson(['unread' => true]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('inbox.show', $threadId))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inbox/Show')->where('thread.id', $threadId));

        $this->assertTrue(Thread::query()->findOrFail($threadId)->is_read);
    }

    public function test_users_cannot_open_another_tenants_thread(): void
    {
        $user = User::factory()->create();
        $mine = Organization::factory()->create();
        $mine->users()->attach($user->id, ['role' => 'owner']);
        $this->mailbox('support@theirs.test');

        $threadId = $this->postGeneric(['to' => ['support@theirs.test']])->json('thread_id');

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $mine->id])
            ->get(route('inbox.show', $threadId))
            ->assertNotFound();
    }

    public function test_outbound_email_gets_message_id_so_replies_thread_back(): void
    {
        $user = User::factory()->create();
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);
        $org = Organization::factory()->create(['mail_provider_id' => $provider->id, 'default_provider' => 'resend']);
        $org->users()->attach($user->id, ['role' => 'owner']);
        Domain::factory()->verified()->create(['organization_id' => $org->id, 'name' => 'acme.test']);
        $mailbox = $this->mailbox('support@acme.test', $org);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('emails.store'), [
                'from' => 'support@acme.test',
                'to' => 'jane@example.com',
                'subject' => 'Your order',
                'html' => '<p>Shipped!</p>',
            ])
            ->assertRedirect();

        $outbound = Message::query()->where('direction', 'outbound')->firstOrFail();
        $this->assertMatchesRegularExpression('/^<[0-9a-f-]{36}@acme\.test>$/', $outbound->message_id_header);
        $this->assertSame($outbound->message_id_header, $outbound->headers['Message-ID']);
        $this->assertSame($mailbox->id, $outbound->thread->mailbox_id);

        $this->postGeneric([
            'subject' => 'Re: Your order',
            'message_id' => '<reply-to-outbound@example.com>',
            'in_reply_to' => $outbound->message_id_header,
        ])->assertCreated()->assertJsonPath('thread_id', $outbound->thread_id);

        $this->assertSame(2, $outbound->thread->fresh()->message_count);
    }

    public function test_reply_from_inbox_is_sent_in_the_same_thread_with_threading_headers(): void
    {
        $user = User::factory()->create();
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);
        $org = Organization::factory()->create(['mail_provider_id' => $provider->id, 'default_provider' => 'resend']);
        $org->users()->attach($user->id, ['role' => 'owner']);
        $this->mailbox('support@acme.test', $org);

        $threadId = $this->postGeneric(['reply_to' => 'jane.alt@example.com'])->json('thread_id');

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->from(route('inbox'))
            ->post(route('inbox.reply', $threadId), ['html' => '<p>It ships tomorrow.</p>'])
            ->assertRedirect(route('inbox'));

        $reply = Message::query()->where('direction', 'outbound')->firstOrFail();
        $this->assertSame($threadId, $reply->thread_id);
        $this->assertSame(['jane.alt@example.com'], $reply->to);
        $this->assertSame('support@acme.test', $reply->from_email);
        $this->assertSame('Re: Help with my order', $reply->subject);
        $this->assertSame('<first@example.com>', $reply->in_reply_to);
        $this->assertSame('<first@example.com>', $reply->headers['In-Reply-To']);
        $this->assertSame('<first@example.com>', $reply->headers['References']);
        $this->assertSame('It ships tomorrow.', $reply->text_body);

        $thread = Thread::query()->findOrFail($threadId);
        $this->assertSame(2, $thread->message_count);
        $this->assertTrue($thread->is_read);
        $this->assertSame(1, Thread::query()->count());

        // The customer's answer to our reply joins the same conversation.
        $this->postGeneric([
            'message_id' => '<answer@example.com>',
            'in_reply_to' => $reply->message_id_header,
            'subject' => 'Re: Help with my order',
        ])->assertJsonPath('thread_id', $threadId);
        $this->assertSame(3, $thread->fresh()->message_count);
    }

    public function test_empty_reply_is_rejected_and_other_tenants_threads_are_404(): void
    {
        $user = User::factory()->create();
        $mine = Organization::factory()->create();
        $mine->users()->attach($user->id, ['role' => 'owner']);
        $this->mailbox('support@theirs.test');
        $theirs = $this->postGeneric(['to' => ['support@theirs.test']])->json('thread_id');

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $mine->id])
            ->post(route('inbox.reply', $theirs), ['html' => '<p>hi</p>'])
            ->assertNotFound();

        $mailbox = $this->mailbox('support@mine.test', $mine);
        $own = $this->postGeneric(['to' => ['support@mine.test'], 'message_id' => '<m2@example.com>'])->json('thread_id');

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $mine->id])
            ->post(route('inbox.reply', $own), ['html' => '<p></p>'])
            ->assertSessionHasErrors('html');

        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
    }

    // ---- Production hardening: secrets, retries, parsing, logging ----------

    private function inProduction(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
    }

    /** @return array<string, mixed> */
    private function resendReceived(array $data = []): array
    {
        return [
            'type' => 'email.received',
            'created_at' => '2026-09-25T21:00:00.000Z',
            'data' => array_merge([
                'email_id' => 're_789',
                'from' => 'Jane <jane@example.com>',
                'to' => ['support@acme.test'],
                'subject' => 'Live receive',
                'message_id' => '<live-1@example.com>',
            ], $data),
        ];
    }

    public function test_missing_secrets_in_production_return_503_instead_of_accepting_or_401(): void
    {
        $this->mailbox();
        $this->inProduction();
        config([
            'maildesk.inbound.generic_secret' => null,
            'maildesk.inbound.resend_webhook_secret' => null,
        ]);

        $this->postGeneric()->assertStatus(503);
        $this->postResend($this->resendReceived(['text' => 'x']))->assertStatus(503);

        $this->assertSame(0, Message::query()->count());
    }

    public function test_missing_secret_is_still_allowed_locally_for_development(): void
    {
        $this->mailbox();
        config(['maildesk.inbound.resend_webhook_secret' => null]);

        $this->call('POST', '/api/v1/inbound/resend', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($this->resendReceived(['text' => 'dev body'])))->assertStatus(202);
    }

    public function test_production_with_secret_still_requires_a_valid_signature(): void
    {
        $this->mailbox();
        $this->inProduction();

        $this->postResend($this->resendReceived(['text' => 'ok']))->assertStatus(202);
        $this->postResend($this->resendReceived(['text' => 'ok', 'message_id' => '<x@y>']), 'whsec_'.base64_encode('wrong'))
            ->assertUnauthorized();
    }

    public function test_resend_rejects_missing_headers_and_tampered_bodies(): void
    {
        $this->mailbox();
        $signedBody = json_encode($this->resendReceived(['text' => 'original']));
        $id = 'msg_tamper';
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$signedBody}", base64_decode(substr(self::SECRET, 6)), true));

        // Valid signature for one body, different body sent.
        $this->call('POST', '/api/v1/inbound/resend', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_SVIX_ID' => $id,
            'HTTP_SVIX_TIMESTAMP' => $ts,
            'HTTP_SVIX_SIGNATURE' => "v1,{$sig}",
        ], json_encode($this->resendReceived(['text' => 'tampered'])))->assertUnauthorized();

        // No Svix headers at all.
        $this->postJson('/api/v1/inbound/resend', $this->resendReceived(['text' => 'x']))->assertUnauthorized();

        $this->assertSame(0, Message::query()->count());
    }

    public function test_resend_webhook_returns_202_immediately(): void
    {
        $this->mailbox();
        Http::fake();

        $this->postResend($this->resendReceived())->assertStatus(202);

        $this->assertSame(0, Message::query()->count());
    }

    public function test_resend_processing_job_retries_on_api_outage(): void
    {
        $this->mailbox();

        Http::fake(['api.resend.com/*' => Http::response(['message' => 'down'], 503)]);

        $job = new ProcessResendInboundEmail('re_789', [
            'from' => 'sender@example.com',
            'to' => ['support@acme.test'],
            'subject' => 'Test',
        ]);

        $this->expectException(\RuntimeException::class);
        $job->handle();
    }

    public function test_resend_permanent_fetch_failure_does_not_store_message(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['message' => 'not found'], 404)]);
        $this->mailbox();

        $job = new ProcessResendInboundEmail('re_789', [
            'from' => 'sender@example.com',
            'to' => ['support@acme.test'],
            'subject' => 'Live receive',
        ]);
        $job->handle();

        $this->assertSame(0, Message::query()->count());
    }

    public function test_resend_processing_job_uses_headers_for_threading(): void
    {
        $this->mailbox();
        $this->postGeneric(['message_id' => '<original@example.com>'])->assertCreated();
        $originalThread = Thread::query()->firstOrFail();

        Http::fake([
            'api.resend.com/emails/receiving/re_789' => Http::response([
                'object' => 'email',
                'text' => 'Following up',
                'in_reply_to' => '<original@example.com>',
                'headers' => ['In-Reply-To' => '<original@example.com>'],
                'received_for' => ['support@acme.test'],
            ]),
            'api.resend.com/emails/receiving/re_789/attachments' => Http::response(['data' => []]),
        ]);

        $job = new ProcessResendInboundEmail('re_789', [
            'from' => 'sender@example.com',
            'to' => ['support@acme.test'],
            'subject' => 'Different subject',
        ]);
        $job->handle();

        $resendMessage = Message::query()->where('provider_message_id', 're_789')->firstOrFail();
        $this->assertSame($originalThread->id, $resendMessage->thread_id);
        $this->assertSame('<original@example.com>', $resendMessage->in_reply_to);
        $this->assertSame('Following up', $resendMessage->text_body);
    }

    public function test_resend_processing_job_downloads_attachments(): void
    {
        $this->mailbox();

        Http::fake([
            'api.resend.com/emails/receiving/re_789' => Http::response([
                'text' => 'See attached',
                'received_for' => ['support@acme.test'],
            ]),
            'api.resend.com/emails/receiving/re_789/attachments' => Http::response(['object' => 'list', 'data' => [
                ['id' => 'att_1', 'filename' => 'invoice.pdf', 'content_type' => 'application/pdf', 'download_url' => 'https://files.resend.test/att_1'],
                ['id' => 'att_2', 'filename' => 'broken.png', 'content_type' => 'image/png', 'download_url' => 'https://files.resend.test/att_2'],
            ]]),
            'files.resend.test/att_1' => Http::response('%PDF-1.7 fake'),
            'files.resend.test/att_2' => Http::response('', 403),
        ]);

        $job = new ProcessResendInboundEmail('re_789', [
            'from' => 'sender@example.com',
            'to' => ['support@acme.test'],
            'subject' => 'With attachments',
        ]);
        $job->handle();

        $message = Message::query()->firstOrFail();
        $this->assertSame('See attached', $message->text_body);

        $attachment = Attachment::query()->sole();
        $this->assertSame('invoice.pdf', $attachment->filename);
        $this->assertSame('%PDF-1.7 fake', Storage::disk('local')->get($attachment->path));
    }

    public function test_inbound_outcomes_are_logged_to_the_configured_channel(): void
    {
        $path = storage_path('logs/inbound-test-'.uniqid().'.log');
        config([
            'logging.channels.inbound_test' => ['driver' => 'single', 'path' => $path, 'level' => 'debug'],
            'maildesk.inbound.log_channel' => 'inbound_test',
        ]);
        $this->mailbox();

        $this->postGeneric()->assertCreated();
        $this->postGeneric()->assertOk();                        // duplicate
        $this->postGeneric([], 'wrong')->assertUnauthorized();
        $this->postResend(['type' => 'email.delivered', 'data' => []])->assertOk();

        $log = file_get_contents($path);
        @unlink($path);

        $this->assertStringContainsString('Inbound email received', $log);
        $this->assertStringContainsString('Inbound email duplicate ignored', $log);
        $this->assertStringContainsString('Inbound email rejected: invalid signature', $log);
        $this->assertStringContainsString('Delivery event ignored', $log);
        $this->assertStringNotContainsString('my order has not arrived', $log); // bodies are never logged
    }
}
