<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\ChatAttachment;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\MailDraft;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MobileInboxExtrasTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_matching_threads_with_labels_and_archive_moves_them(): void
    {
        [, $organization, $token] = $this->member();
        $invoice = Thread::factory()->create([
            'organization_id' => $organization->id,
            'subject' => 'Invoice 14',
            'snippet' => 'Please pay',
            'is_read' => false,
            'ai' => ['priority' => 'normal', 'intent' => 'billing'],
            'last_message_at' => now(),
        ]);
        Message::factory()->create([
            'organization_id' => $organization->id,
            'thread_id' => $invoice->id,
            'direction' => 'inbound',
            'from_email' => 'billing@vendor.test',
            'from_name' => 'Vendor',
            'subject' => 'Invoice 14',
        ]);
        Thread::factory()->create([
            'organization_id' => $organization->id,
            'subject' => 'Hello team',
            'snippet' => 'standup notes',
        ]);

        $this->asApp($token, $organization)->getJson('/api/app/inbox/threads?q=invoice')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject', 'Invoice 14')
            ->assertJsonPath('data.0.labels.0', 'Normal')
            ->assertJsonPath('data.0.labels.1', 'Billing')
            ->assertJsonPath('data.0.from_email', 'billing@vendor.test');

        $this->asApp($token, $organization)->postJson("/api/app/inbox/threads/{$invoice->id}/archive")
            ->assertOk();

        $this->asApp($token, $organization)->getJson('/api/app/inbox/threads')
            ->assertOk()
            ->assertJsonMissing(['subject' => 'Invoice 14']);

        $this->asApp($token, $organization)->getJson('/api/app/inbox/threads?folder=archive')
            ->assertOk()
            ->assertJsonPath('data.0.subject', 'Invoice 14');
    }

    public function test_reply_stays_on_the_same_thread(): void
    {
        [, $organization, $token] = $this->sender();
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $organization->id,
            'email' => 'hello@acme.test',
            'display_name' => 'Acme',
        ]);
        $thread = Thread::factory()->create([
            'organization_id' => $organization->id,
            'mailbox_id' => $mailbox->id,
            'subject' => 'Question',
        ]);
        Message::factory()->inbound()->create([
            'organization_id' => $organization->id,
            'thread_id' => $thread->id,
            'mailbox_id' => $mailbox->id,
            'from_email' => 'customer@example.com',
            'to' => ['hello@acme.test'],
            'subject' => 'Question',
            'message_id_header' => '<orig@acme.test>',
        ]);

        $this->asApp($token, $organization)->post("/api/app/inbox/threads/{$thread->id}/reply", [
            'body' => 'Thanks, we are on it.',
        ])->assertCreated()
            ->assertJsonPath('data.thread_id', $thread->id);

        $this->assertDatabaseHas('messages', [
            'thread_id' => $thread->id,
            'direction' => 'outbound',
            'subject' => 'Re: Question',
            'status' => 'sent',
        ]);
    }

    public function test_attachment_download_stays_inside_the_workspace(): void
    {
        Storage::fake('local');
        [, $organization, $token] = $this->member();
        $thread = Thread::factory()->create(['organization_id' => $organization->id]);
        $message = Message::factory()->create([
            'organization_id' => $organization->id,
            'thread_id' => $thread->id,
            'direction' => 'inbound',
        ]);
        $path = UploadedFile::fake()->create('invoice.pdf', 12, 'application/pdf')->store('mail', 'local');
        $attachment = Attachment::query()->create([
            'message_id' => $message->id,
            'filename' => 'invoice.pdf',
            'content_type' => 'application/pdf',
            'size' => 12,
            'disk' => 'local',
            'path' => $path,
        ]);

        $this->asApp($token, $organization)->get("/api/app/inbox/attachments/{$attachment->id}")
            ->assertOk();

        $this->asApp($token, $organization)->getJson("/api/app/inbox/threads/{$thread->id}")
            ->assertOk()
            ->assertJsonPath('data.messages.0.attachments.0.filename', 'invoice.pdf');

        [, $otherOrganization, $otherToken] = $this->member();

        $this->asApp($otherToken, $otherOrganization)
            ->get("/api/app/inbox/attachments/{$attachment->id}")
            ->assertNotFound();
    }

    public function test_chat_image_can_be_pinned_and_a_contact_can_be_added(): void
    {
        Storage::fake('local');
        [, $organization, $token] = $this->member();
        $other = User::factory()->create();
        $later = User::factory()->create();
        $organization->users()->attach($other->id, ['role' => 'member']);
        $organization->users()->attach($later->id, ['role' => 'member']);

        $pinnedId = $this->asApp($token, $organization)->postJson('/api/app/conversations', [
            'type' => 'direct',
            'user_ids' => [$other->id],
        ])->json('data.id');

        $newerId = $this->asApp($token, $organization)->postJson('/api/app/conversations', [
            'type' => 'direct',
            'user_ids' => [$later->id],
        ])->json('data.id');

        $this->asApp($token, $organization)->post("/api/app/conversations/{$pinnedId}/messages", [
            'body' => 'see this',
            'kind' => 'image',
            'file' => UploadedFile::fake()->image('shot.png'),
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'image')
            ->assertJsonPath('data.attachments.0.filename', 'shot.png');

        $attachmentId = ChatAttachment::query()->value('id');

        $this->asApp($token, $organization)
            ->get("/api/app/chat/attachments/{$attachmentId}?inline=1")
            ->assertOk();

        $this->asApp($token, $organization)->postJson("/api/app/conversations/{$pinnedId}/pin", [
            'pinned' => true,
        ])->assertOk()
            ->assertJsonPath('data.pinned', true);

        $this->travel(1)->second();

        $this->asApp($token, $organization)->postJson("/api/app/conversations/{$newerId}/messages", [
            'body' => 'later',
        ])->assertCreated();

        $this->asApp($token, $organization)->getJson('/api/app/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $pinnedId)
            ->assertJsonPath('data.0.pinned', true)
            ->assertJsonPath('data.0.preview', 'see this');

        $this->asApp($token, $organization)->postJson('/api/app/contacts', [
            'email' => 'Ada@Vendor.test',
            'name' => 'Ada Vendor',
            'company' => 'Vendor',
        ])->assertCreated()
            ->assertJsonPath('data.email', 'ada@vendor.test')
            ->assertJsonPath('data.name', 'Ada Vendor');

        $this->assertDatabaseHas('contacts', [
            'organization_id' => $organization->id,
            'email' => 'ada@vendor.test',
            'company' => 'Vendor',
        ]);
    }

    public function test_compose_keeps_every_attached_file(): void
    {
        Storage::fake('local');
        [, $organization, $token] = $this->sender();

        $this->asApp($token, $organization)->post('/api/app/inbox/send', [
            'to' => 'customer@example.com',
            'subject' => 'Files',
            'body' => 'See attached.',
            'files' => [
                UploadedFile::fake()->image('photo.png'),
                UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf'),
            ],
        ])->assertCreated();

        $this->assertDatabaseCount('attachments', 2);
        $this->assertDatabaseHas('attachments', ['filename' => 'photo.png']);
        $this->assertDatabaseHas('attachments', ['filename' => 'notes.pdf']);
    }

    public function test_marking_the_inbox_read_clears_unread_mail(): void
    {
        [, $organization, $token] = $this->member();
        Thread::factory()->create([
            'organization_id' => $organization->id,
            'is_read' => false,
            'subject' => 'Hello',
        ]);
        Thread::factory()->create([
            'organization_id' => $organization->id,
            'is_read' => true,
            'subject' => 'Old',
        ]);

        $this->asApp($token, $organization)->postJson('/api/app/inbox/read')
            ->assertOk()
            ->assertJsonPath('message', 'Marked as read.')
            ->assertJsonPath('count', 1);

        $this->assertDatabaseHas('threads', [
            'organization_id' => $organization->id,
            'subject' => 'Hello',
            'is_read' => true,
        ]);
    }

    public function test_compose_sends_cc_and_bcc(): void
    {
        [, $organization, $token] = $this->sender();

        $this->asApp($token, $organization)->postJson('/api/app/inbox/send', [
            'to' => 'customer@example.com',
            'cc' => 'boss@example.com',
            'bcc' => 'audit@example.com',
            'subject' => 'Hi',
            'body' => 'Hello',
        ])->assertCreated();

        $message = Message::query()->firstOrFail();
        $this->assertSame(['boss@example.com'], collect($message->cc)->map(fn ($address) => is_array($address) ? $address['email'] : $address)->all());
        $this->assertSame(['audit@example.com'], collect($message->bcc)->map(fn ($address) => is_array($address) ? $address['email'] : $address)->all());
    }

    public function test_compose_rejects_an_invalid_cc(): void
    {
        [, $organization, $token] = $this->sender();

        $this->asApp($token, $organization)->postJson('/api/app/inbox/send', [
            'to' => 'customer@example.com',
            'cc' => 'not-an-address',
            'subject' => 'Hi',
            'body' => 'Hello',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.cc.0', '"not-an-address" is not a valid email address.');

        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
    }

    public function test_ai_stays_unavailable_until_it_is_enabled(): void
    {
        [, $organization, $token] = $this->member();
        $thread = Thread::factory()->create([
            'organization_id' => $organization->id,
        ]);

        $this->asApp($token, $organization)->getJson('/api/app/ai')
            ->assertOk()
            ->assertJsonPath('compose_assist', false)
            ->assertJsonPath('reply_draft', false)
            ->assertJsonPath('thread_summary', false);

        $this->asApp($token, $organization)->postJson('/api/app/ai/compose', [
            'action' => 'rewrite',
            'body' => 'Hello there',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Compose assist is not enabled.');

        $this->asApp($token, $organization)->postJson("/api/app/inbox/threads/{$thread->id}/suggest")
            ->assertForbidden()
            ->assertJsonPath('message', 'Reply draft is not enabled.');
    }

    public function test_draft_is_listed_for_the_signed_in_user(): void
    {
        [, $organization, $token] = $this->member();

        $this->asApp($token, $organization)->postJson('/api/app/inbox/drafts', [
            'to' => 'ada@example.com',
            'cc' => 'boss@example.com',
            'bcc' => 'audit@example.com',
            'subject' => 'Hold',
            'body' => 'Not yet',
        ])->assertCreated();

        $this->assertDatabaseHas('mail_drafts', [
            'organization_id' => $organization->id,
            'cc' => 'boss@example.com',
            'bcc' => 'audit@example.com',
        ]);

        $listed = $this->asApp($token, $organization)->getJson('/api/app/inbox/drafts')
            ->assertOk()
            ->assertJsonPath('data.0.subject', 'Hold')
            ->assertJsonPath('data.0.to', 'ada@example.com')
            ->assertJsonPath('data.0.cc', 'boss@example.com')
            ->assertJsonPath('data.0.bcc', 'audit@example.com')
            ->assertJsonPath('data.0.body', 'Not yet');

        $this->asApp($token, $organization)->postJson('/api/app/inbox/drafts', [
            'id' => $listed->json('data.0.id'),
            'to' => 'ada@example.com',
            'subject' => 'Hold',
            'body' => 'Ready now',
        ])->assertOk();

        $this->assertSame(1, MailDraft::query()->count());
        $this->assertDatabaseHas('mail_drafts', [
            'organization_id' => $organization->id,
            'html' => '<p>Ready now</p>',
        ]);
    }

    /**
     * @return array{0: User, 1: Organization, 2: string}
     */
    private function member(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $organization, $user->createToken('test')->plainTextToken];
    }

    /**
     * @return array{0: User, 1: Organization, 2: string}
     */
    private function sender(): array
    {
        $provider = MailProvider::factory()->create([
            'key' => 'resend',
            'driver' => 'resend',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $organization = Organization::factory()->create([
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
        ]);
        $organization->users()->attach($user->id, ['role' => 'owner']);
        Domain::factory()->verified()->create([
            'organization_id' => $organization->id,
            'name' => 'acme.test',
        ]);

        return [$user, $organization, $user->createToken('test')->plainTextToken];
    }

    private function asApp(string $token, Organization $organization): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->withHeader('X-Organization-Id', (string) $organization->id);
    }
}
