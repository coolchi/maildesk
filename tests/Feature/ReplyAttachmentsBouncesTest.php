<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Suppression;
use App\Models\Thread;
use App\Models\User;
use App\Support\AddressList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReplyAttachmentsBouncesTest extends TestCase
{
    use RefreshDatabase;

    private function member(): array
    {
        $user = User::factory()->create();
        $provider = MailProvider::query()->where('key', 'resend')->first()
            ?? MailProvider::factory()->create(['key' => 'resend', 'driver' => 'resend', 'status' => 'active']);
        $org = Organization::factory()->create([
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $org];
    }

    private function as(User $user, Organization $org): static
    {
        return $this->actingAs($user)->withSession(['current_organization_id' => $org->id]);
    }

    /** A thread with one inbound customer message. */
    private function thread(Organization $org): array
    {
        Domain::factory()->verified()->create(['organization_id' => $org->id, 'name' => 'acme.test']);
        $mailbox = Mailbox::factory()->create(['organization_id' => $org->id, 'email' => 'support@acme.test']);
        $thread = Thread::factory()->create(['organization_id' => $org->id, 'mailbox_id' => $mailbox->id, 'subject' => 'Help']);
        $inbound = Message::factory()->create([
            'organization_id' => $org->id,
            'thread_id' => $thread->id,
            'direction' => 'inbound',
            'status' => 'received',
            'from_email' => 'customer@example.com',
            'to' => ['support@acme.test'],
            'subject' => 'Help',
            'message_id_header' => '<in-1@example.com>',
        ]);

        return [$thread, $inbound];
    }

    private function attach(Message $message, string $name, string $type, string $body = 'data'): Attachment
    {
        $path = 'attachments/'.$message->organization_id.'/'.$name;
        Storage::disk('local')->put($path, $body);

        return Attachment::query()->create([
            'message_id' => $message->id,
            'filename' => $name,
            'content_type' => $type,
            'size' => strlen($body),
            'disk' => 'local',
            'path' => $path,
        ]);
    }

    public function test_address_list_parses_commas_semicolons_and_names(): void
    {
        $this->assertSame(
            ['a@example.com', 'b@example.com', 'c@example.com'],
            AddressList::parse('a@example.com; "Doe, Jane" <b@example.com>, c@example.com'),
        );
        $this->assertSame([], AddressList::parse(null));
    }

    public function test_thread_payload_includes_inbound_attachments(): void
    {
        Storage::fake('local');
        [$user, $org] = $this->member();
        [$thread, $inbound] = $this->thread($org);
        $image = $this->attach($inbound, 'photo.png', 'image/png');
        $this->attach($inbound, 'invoice.pdf', 'application/pdf', str_repeat('x', 2048));

        $this->as($user, $org)->get(route('inbox'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inbox/Index')
                ->where('threads.0.messages.0.attachments.0.filename', 'photo.png')
                ->where('threads.0.messages.0.attachments.0.is_image', true)
                ->where('threads.0.messages.0.attachments.0.url', route('attachments.download', $image->id))
                ->where('threads.0.messages.0.attachments.1.filename', 'invoice.pdf')
                ->where('threads.0.messages.0.attachments.1.is_image', false)
                ->where('threads.0.messages.0.attachments.1.preview_url', null)
                ->where('threads.0.messages.0.attachments.1.size_label', '2 KB'));
    }

    public function test_attachment_download_is_scoped_and_only_previews_raster_images(): void
    {
        Storage::fake('local');
        [$user, $org] = $this->member();
        [, $inbound] = $this->thread($org);
        $png = $this->attach($inbound, 'photo.png', 'image/png');
        $svg = $this->attach($inbound, 'logo.svg', 'image/svg+xml', '<svg onload="alert(1)"/>');

        $this->as($user, $org)->get(route('attachments.download', $png->id))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('photo.png');

        $inline = $this->as($user, $org)->get(route('attachments.download', ['attachment' => $png->id, 'inline' => 1]));
        $inline->assertOk();
        $this->assertStringStartsWith('inline', (string) $inline->headers->get('Content-Disposition'));

        // SVG is never served inline, even when asked.
        $this->as($user, $org)->get(route('attachments.download', ['attachment' => $svg->id, 'inline' => 1]))
            ->assertOk()
            ->assertDownload('logo.svg');

        [$stranger, $otherOrg] = $this->member();
        $this->as($stranger, $otherOrg)->get(route('attachments.download', $png->id))->assertNotFound();

        Storage::disk('local')->delete($png->path);
        $this->as($user, $org)->get(route('attachments.download', $png->id))->assertNotFound();
    }

    public function test_reply_sends_cc_bcc_and_attachments(): void
    {
        Storage::fake('local');
        [$user, $org] = $this->member();
        [$thread] = $this->thread($org);

        $this->as($user, $org)->post(route('inbox.reply', $thread->id), [
            'html' => '<p>Here you go</p>',
            'cc' => 'boss@example.com; "Ops" <ops@example.com>',
            'bcc' => 'audit@example.com',
            'attachments' => [
                UploadedFile::fake()->create('report.pdf', 20, 'application/pdf'),
                UploadedFile::fake()->image('shot.png'),
            ],
        ])->assertSessionHas('success');

        $reply = Message::query()->where('direction', 'outbound')->with('attachments')->firstOrFail();
        $this->assertSame('sent', $reply->status);
        $this->assertSame($thread->id, $reply->thread_id);
        $this->assertSame(['boss@example.com', 'ops@example.com'], collect($reply->cc)->map(fn ($e) => is_array($e) ? $e['email'] : $e)->all());
        $this->assertSame(['audit@example.com'], collect($reply->bcc)->map(fn ($e) => is_array($e) ? $e['email'] : $e)->all());
        $this->assertCount(2, $reply->attachments);
        $this->assertSame(2, data_get($reply->meta, 'provider_raw.attachments'));
        foreach ($reply->attachments as $attachment) {
            Storage::disk('local')->assertExists($attachment->path);
        }
    }

    public function test_reply_rejects_invalid_cc(): void
    {
        [$user, $org] = $this->member();
        [$thread] = $this->thread($org);

        $this->as($user, $org)->post(route('inbox.reply', $thread->id), [
            'html' => '<p>Hi</p>',
            'cc' => 'not-an-address',
        ])->assertSessionHasErrors('cc');

        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
    }

    public function test_compose_accepts_bcc(): void
    {
        [$user, $org] = $this->member();
        Domain::factory()->verified()->create(['organization_id' => $org->id, 'name' => 'acme.test']);

        $this->as($user, $org)->post(route('emails.store'), [
            'from' => 'hello@acme.test',
            'to' => 'customer@example.com',
            'bcc' => 'hidden@example.com',
            'subject' => 'Hi',
            'html' => '<p>Hi</p>',
        ]);

        $message = Message::query()->firstOrFail();
        $this->assertSame(['hidden@example.com'], collect($message->bcc)->map(fn ($e) => is_array($e) ? $e['email'] : $e)->all());
    }

    public function test_failed_reply_can_be_retried(): void
    {
        [$user, $org] = $this->member();
        [$thread] = $this->thread($org);
        $failed = Message::factory()->create([
            'organization_id' => $org->id,
            'thread_id' => $thread->id,
            'direction' => 'outbound',
            'status' => 'failed',
            'from_email' => 'support@acme.test',
            'to' => ['customer@example.com'],
            'subject' => 'Re: Help',
            'meta' => ['error' => 'Temporary provider outage'],
        ]);

        $this->as($user, $org)->get(route('inbox'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('threads.0.messages.1.can_retry', true)
                ->where('threads.0.messages.1.error', 'Temporary provider outage'));

        $this->as($user, $org)->post(route('emails.retry', $failed->uuid))->assertSessionHas('success');

        $failed->refresh();
        $this->assertSame('sent', $failed->status);
        $this->assertNull(data_get($failed->meta, 'error'));
        $this->assertSame('Temporary provider outage', data_get($failed->meta, 'attempts.0.error'));

        // Only failed messages can be retried.
        $this->as($user, $org)->post(route('emails.retry', $failed->uuid))->assertSessionHas('error');
    }

    public function test_retry_is_blocked_when_recipient_is_now_suppressed(): void
    {
        [$user, $org] = $this->member();
        $failed = Message::factory()->create([
            'organization_id' => $org->id,
            'direction' => 'outbound',
            'status' => 'failed',
            'from_email' => 'hello@acme.test',
            'to' => ['gone@example.com'],
            'meta' => ['error' => 'boom'],
        ]);
        Suppression::factory()->create(['organization_id' => $org->id, 'email' => 'gone@example.com']);

        $this->as($user, $org)->post(route('emails.retry', $failed->uuid))->assertSessionHas('error');
        $this->assertSame('suppressed', $failed->fresh()->status);
    }

    public function test_bounced_page_lists_bounces_with_type_suppression_and_events(): void
    {
        [$user, $org] = $this->member();
        $hard = Message::factory()->create([
            'organization_id' => $org->id,
            'direction' => 'outbound',
            'status' => 'bounced',
            'from_email' => 'hello@acme.test',
            'to' => ['dead@example.com'],
            'subject' => 'Hard one',
            'sent_at' => now()->subHour(),
            'meta' => [
                'bounce' => ['type' => 'Permanent', 'reason' => 'Mailbox does not exist', 'at' => now()->subMinutes(50)->toIso8601String()],
                'events' => [
                    ['type' => 'bounced', 'at' => now()->subMinutes(50)->toIso8601String(), 'bounce_type' => 'Permanent', 'reason' => 'Mailbox does not exist'],
                ],
            ],
        ]);
        Message::factory()->create([
            'organization_id' => $org->id,
            'direction' => 'outbound',
            'status' => 'delivered',
            'from_email' => 'hello@acme.test',
            'to' => ['full@example.com'],
            'subject' => 'Soft one',
            'meta' => ['bounce' => ['type' => 'Transient', 'reason' => 'Mailbox full']],
        ]);
        Message::factory()->create([
            'organization_id' => $org->id,
            'direction' => 'outbound',
            'status' => 'delivered',
            'to' => ['fine@example.com'],
            'subject' => 'Fine',
        ]);
        Suppression::factory()->create(['organization_id' => $org->id, 'email' => 'dead@example.com', 'source' => 'bounce']);
        [, $other] = $this->member();
        Message::factory()->create(['organization_id' => $other->id, 'direction' => 'outbound', 'status' => 'bounced', 'to' => ['x@example.com']]);

        $this->as($user, $org)->get(route('bounced'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bounced/Index')
                ->has('bounces', 2)
                ->where('counts.all', 2)
                ->where('counts.hard', 1)
                ->where('counts.soft', 1)
                ->where('counts.suppressed', 1));

        $this->as($user, $org)->get(route('bounced', ['type' => 'hard']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('bounces', 1)
                ->where('bounces.0.id', $hard->uuid)
                ->where('bounces.0.to', 'dead@example.com')
                ->where('bounces.0.type', 'hard')
                ->where('bounces.0.reason', 'Mailbox does not exist')
                ->where('bounces.0.suppressed', true)
                ->where('bounces.0.suppression.email', 'dead@example.com')
                ->has('bounces.0.events', 2)
                ->where('bounces.0.events.0.type', 'sent')
                ->where('bounces.0.events.1.type', 'bounced'));

        $this->as($user, $org)->get(route('bounced', ['type' => 'soft']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('bounces', 1)
                ->where('bounces.0.type', 'soft')
                ->where('bounces.0.suppressed', false));

        $this->as($user, $org)->get(route('bounced', ['q' => 'full']))
            ->assertInertia(fn (Assert $page) => $page->has('bounces', 1)->where('bounces.0.subject', 'Soft one'));
    }
}
