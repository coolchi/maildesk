<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Attachment;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApiMailboxesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $otherOrg;

    private User $user;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['maildesk.stalwart.enabled' => false]);

        $this->user = User::factory()->create();
        $this->org = Organization::factory()->create();
        $this->org->users()->attach($this->user->id, ['role' => 'owner']);
        $this->domain = Domain::factory()->verified()->create(['organization_id' => $this->org->id, 'name' => 'acme.test']);
        Domain::factory()->create(['organization_id' => $this->org->id, 'name' => 'pending.test', 'status' => 'pending']);

        $this->otherOrg = Organization::factory()->create();
        Domain::factory()->verified()->create(['organization_id' => $this->otherOrg->id, 'name' => 'other.test']);
    }

    private function token(array $abilities = ['*'], ?Organization $org = null): string
    {
        return ApiKey::issue($org ?? $this->org, 'Test', $this->user, $abilities)['plain'];
    }

    private function api(?string $token = null)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.($token ?? $this->token()),
            'Accept' => 'application/json',
        ]);
    }

    private function enableStalwart(): void
    {
        config([
            'maildesk.stalwart.enabled' => true,
            'maildesk.stalwart.url' => 'http://stalwart.test',
            'maildesk.stalwart.admin_user' => 'admin@test',
            'maildesk.stalwart.admin_password' => 'secret',
            'maildesk.stalwart.auto_provision' => true,
            'maildesk.stalwart.clients.imap' => ['host' => 'mail.test', 'port' => 993, 'encryption' => 'ssl'],
            'maildesk.stalwart.clients.smtp' => ['host' => 'mail.test', 'port' => 465, 'encryption' => 'ssl'],
        ]);

        Http::fake([
            'stalwart.test/jmap' => Http::sequence()
                ->push(['methodResponses' => [['x:Domain/get', ['list' => [['id' => 'dom1', 'name' => 'acme.test']], 'notFound' => []], 'd0']]])
                ->push(['methodResponses' => [['x:Account/get', ['list' => [], 'notFound' => []], 'a0']]])
                ->push(['methodResponses' => [['x:Account/set', ['created' => ['new' => ['id' => 'acc1']]], 'a1']]])
                ->push(['methodResponses' => [['x:Account/set', ['updated' => ['acc1' => null]], 'a2']]]),
        ]);
    }

    private function mailbox(?Organization $org = null, string $email = 'support@acme.test'): Mailbox
    {
        return Mailbox::factory()->create([
            'organization_id' => ($org ?? $this->org)->id,
            'email' => $email,
        ]);
    }

    private function inbound(Mailbox $mailbox, array $thread = [], array $message = []): Message
    {
        $t = Thread::factory()->create([
            'organization_id' => $mailbox->organization_id,
            'mailbox_id' => $mailbox->id,
            'is_read' => false,
            'is_archived' => false,
            'is_spam' => false,
            'is_trashed' => false,
            ...$thread,
        ]);

        return Message::factory()->inbound()->create([
            'organization_id' => $mailbox->organization_id,
            'mailbox_id' => $mailbox->id,
            'thread_id' => $t->id,
            'from_email' => 'jane@customer.test',
            'to' => [['email' => $mailbox->email]],
            ...$message,
        ]);
    }

    public function test_requires_a_full_access_key(): void
    {
        $this->getJson('/api/v1/mailboxes')->assertUnauthorized();

        $sending = $this->token(['emails:send']);
        $this->api($sending)->getJson('/api/v1/mailboxes')->assertForbidden();
        $this->api($sending)->postJson('/api/v1/mailboxes', ['email' => 'a@acme.test'])->assertForbidden();
        $this->api($sending)->getJson('/api/v1/mailboxes/1/messages')->assertForbidden();
    }

    public function test_creates_a_mailbox_with_a_generated_password(): void
    {
        $response = $this->api()->postJson('/api/v1/mailboxes', ['email' => 'Sales@ACME.test', 'name' => 'Sales'])
            ->assertCreated()
            ->assertJsonPath('email', 'sales@acme.test')
            ->assertJsonPath('name', 'Sales')
            ->assertJsonPath('role', 'staff')
            ->assertJsonPath('inbox', true)
            ->assertJsonPath('has_login', true)
            ->assertJsonPath('mail_client.enabled', false);

        $password = $response->json('password');
        $this->assertIsString($password);
        $this->assertGreaterThanOrEqual(16, strlen($password));

        $mailbox = Mailbox::query()->where('email', 'sales@acme.test')->firstOrFail();
        $this->assertSame($this->org->id, $mailbox->organization_id);
        $this->assertSame($this->domain->id, $mailbox->domain_id);
        $this->assertTrue(Hash::check($password, $mailbox->user->password));
        $this->assertTrue($this->org->users()->whereKey($mailbox->user_id)->exists());

        // The password is never readable again.
        $this->api()->getJson('/api/v1/mailboxes/'.$mailbox->id)
            ->assertOk()
            ->assertJsonMissingPath('password');
    }

    public function test_creates_with_local_and_domain_and_a_chosen_password(): void
    {
        $this->api()->postJson('/api/v1/mailboxes', [
            'local' => 'billing',
            'domain' => 'acme.test',
            'password' => 'Sup3r-Secret-Pass!',
            'role' => 'admin',
            'transactional' => true,
        ])->assertCreated()
            ->assertJsonPath('email', 'billing@acme.test')
            ->assertJsonPath('role', 'admin')
            ->assertJsonPath('transactional', true)
            ->assertJsonPath('password', 'Sup3r-Secret-Pass!');
    }

    public function test_provisions_stalwart_and_returns_the_app_password_once(): void
    {
        $this->enableStalwart();

        $response = $this->api()->postJson('/api/v1/mailboxes', ['email' => 'hello@acme.test'])
            ->assertCreated()
            ->assertJsonPath('mail_client.enabled', true)
            ->assertJsonPath('mail_client.imap.host', 'mail.test')
            ->assertJsonPath('imap_enabled', true);

        $mailbox = Mailbox::query()->where('email', 'hello@acme.test')->firstOrFail();
        $this->assertSame('acc1', $mailbox->stalwart_account_id);
        $this->assertSame($mailbox->imap_password, $response->json('mail_client.app_password'));
    }

    public function test_create_validation(): void
    {
        $this->mailbox();
        User::factory()->create(['email' => 'taken@acme.test']);

        $this->api()->postJson('/api/v1/mailboxes', [])->assertUnprocessable();
        $this->api()->postJson('/api/v1/mailboxes', ['email' => 'not-an-address'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->api()->postJson('/api/v1/mailboxes', ['email' => 'bad name@acme.test'])->assertUnprocessable()->assertJsonValidationErrors('local');
        $this->api()->postJson('/api/v1/mailboxes', ['email' => 'x@pending.test'])->assertUnprocessable()->assertJsonValidationErrors('domain');
        $this->api()->postJson('/api/v1/mailboxes', ['email' => 'x@other.test'])->assertUnprocessable()->assertJsonValidationErrors('domain');
        $this->api()->postJson('/api/v1/mailboxes', ['email' => 'support@acme.test'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->api()->postJson('/api/v1/mailboxes', ['email' => 'taken@acme.test'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->api()->postJson('/api/v1/mailboxes', ['email' => 'y@acme.test', 'password' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->api()->postJson('/api/v1/mailboxes', ['email' => 'y@acme.test', 'role' => 'owner'])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->api()->postJson('/api/v1/mailboxes', ['email' => 'y@acme.test', 'inbox' => false])->assertUnprocessable()->assertJsonValidationErrors('inbox');

        $this->assertSame(1, Mailbox::query()->count());
    }

    public function test_list_and_show_are_scoped_to_the_key_organization(): void
    {
        $mine = $this->mailbox();
        $theirs = $this->mailbox($this->otherOrg, 'secret@other.test');

        $this->api()->getJson('/api/v1/mailboxes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'support@acme.test')
            ->assertJsonMissingPath('data.0.imap_password');

        $this->api()->getJson('/api/v1/mailboxes/'.$mine->id)->assertOk()->assertJsonPath('id', $mine->id);
        $this->api()->getJson('/api/v1/mailboxes/'.$theirs->id)->assertNotFound();
        $this->api()->deleteJson('/api/v1/mailboxes/'.$theirs->id)->assertNotFound();
        $this->api()->getJson('/api/v1/mailboxes/'.$theirs->id.'/messages')->assertNotFound();
        $this->assertDatabaseHas('mailboxes', ['id' => $theirs->id]);
    }

    public function test_delete_removes_mailbox_and_its_login(): void
    {
        $created = $this->api()->postJson('/api/v1/mailboxes', ['email' => 'temp@acme.test'])->assertCreated();
        $id = $created->json('id');
        $userId = Mailbox::query()->findOrFail($id)->user_id;

        $this->api()->deleteJson('/api/v1/mailboxes/'.$id)->assertOk()->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('mailboxes', ['id' => $id]);
        $this->assertDatabaseMissing('users', ['id' => $userId]);
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    public function test_lists_inbox_messages_with_filters(): void
    {
        $mailbox = $this->mailbox();
        $other = $this->mailbox(null, 'sales@acme.test');

        $unread = $this->inbound($mailbox, ['is_read' => false], ['subject' => 'Unread one']);
        $read = $this->inbound($mailbox, ['is_read' => true], ['subject' => 'Read one']);
        $archived = $this->inbound($mailbox, ['is_archived' => true], ['subject' => 'Archived']);
        $spam = $this->inbound($mailbox, ['is_spam' => true], ['subject' => 'Spam']);
        $this->inbound($other, [], ['subject' => 'Other mailbox']);
        Message::factory()->create([
            'organization_id' => $this->org->id,
            'mailbox_id' => $mailbox->id,
            'thread_id' => $unread->thread_id,
            'direction' => 'outbound',
            'subject' => 'Our reply',
        ]);

        $response = $this->api()->getJson("/api/v1/mailboxes/{$mailbox->id}/messages")->assertOk();
        $this->assertEqualsCanonicalizing(['Unread one', 'Read one'], collect($response->json('data'))->pluck('subject')->all());
        $response->assertJsonPath('data.0.from.email', 'jane@customer.test')
            ->assertJsonPath('data.0.to.0', 'support@acme.test')
            ->assertJsonPath('data.0.folder', 'inbox')
            ->assertJsonMissingPath('data.0.html');

        $this->api()->getJson("/api/v1/mailboxes/{$mailbox->id}/messages?unread=1")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $unread->id);
        $this->api()->getJson("/api/v1/mailboxes/{$mailbox->id}/messages?unread=0")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $read->id);
        $this->api()->getJson("/api/v1/mailboxes/{$mailbox->id}/messages?folder=archive")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $archived->id);
        $this->api()->getJson("/api/v1/mailboxes/{$mailbox->id}/messages?folder=spam")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $spam->id);
        $this->api()->getJson("/api/v1/mailboxes/{$mailbox->id}/messages?direction=outbound")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject', 'Our reply');
        $this->api()->getJson("/api/v1/mailboxes/{$mailbox->id}/messages?folder=all&direction=all")
            ->assertOk()->assertJsonCount(5, 'data');
        $this->api()->getJson("/api/v1/mailboxes/{$mailbox->id}/messages?folder=bogus")
            ->assertUnprocessable()->assertJsonValidationErrors('folder');
    }

    public function test_polls_for_new_messages_with_since_id_and_since(): void
    {
        $mailbox = $this->mailbox();

        $this->travelTo(now()->subHour());
        $old = $this->inbound($mailbox, [], ['subject' => 'Old']);
        $this->travelBack();

        $first = $this->inbound($mailbox, [], ['subject' => 'New 1']);
        $second = $this->inbound($mailbox, [], ['subject' => 'New 2']);

        $this->api()->getJson("/api/v1/mailboxes/{$mailbox->id}/messages?since_id={$old->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id);

        $this->api()->getJson("/api/v1/mailboxes/{$mailbox->id}/messages?since_id={$second->id}")
            ->assertOk()->assertJsonCount(0, 'data');

        $since = urlencode(now()->subMinutes(10)->toIso8601String());
        $this->api()->getJson("/api/v1/mailboxes/{$mailbox->id}/messages?since={$since}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.subject', 'New 1');

        $this->api()->getJson("/api/v1/mailboxes/{$mailbox->id}/messages?since=not-a-date")
            ->assertUnprocessable()->assertJsonValidationErrors('since');
    }

    public function test_shows_a_full_message_by_id_or_uuid(): void
    {
        $mailbox = $this->mailbox();
        $message = $this->inbound($mailbox, [], [
            'text_body' => 'Plain body',
            'html_body' => '<p>Hello</p><script>alert(1)</script>',
            'headers' => ['x-secret' => 'nope'],
        ]);
        Attachment::query()->create([
            'message_id' => $message->id,
            'filename' => 'invoice.pdf',
            'content_type' => 'application/pdf',
            'size' => 1234,
            'disk' => 'local',
            'path' => 'attachments/invoice.pdf',
        ]);

        $response = $this->api()->getJson("/api/v1/mailboxes/{$mailbox->id}/messages/{$message->id}")
            ->assertOk()
            ->assertJsonPath('uuid', $message->uuid)
            ->assertJsonPath('text', 'Plain body')
            ->assertJsonPath('attachments.0.filename', 'invoice.pdf')
            ->assertJsonPath('attachments.0.size', 1234)
            ->assertJsonMissingPath('headers')
            ->assertJsonMissingPath('attachments.0.path');
        $this->assertStringContainsString('Hello', $response->json('html'));
        $this->assertStringNotContainsString('<script', $response->json('html'));

        $this->api()->getJson("/api/v1/mailboxes/{$mailbox->id}/messages/{$message->uuid}")
            ->assertOk()->assertJsonPath('id', $message->id);

        // Viewing does not change read state.
        $this->assertFalse($message->thread->fresh()->is_read);
    }

    public function test_message_must_belong_to_the_mailbox(): void
    {
        $mailbox = $this->mailbox();
        $sibling = $this->mailbox(null, 'sales@acme.test');
        $foreignMailbox = $this->mailbox($this->otherOrg, 'secret@other.test');

        $siblingMessage = $this->inbound($sibling);
        $foreignMessage = $this->inbound($foreignMailbox);

        $this->api()->getJson("/api/v1/mailboxes/{$mailbox->id}/messages/{$siblingMessage->id}")->assertNotFound();
        $this->api()->getJson("/api/v1/mailboxes/{$mailbox->id}/messages/{$foreignMessage->uuid}")->assertNotFound();
        $this->api()->getJson("/api/v1/mailboxes/{$foreignMailbox->id}/messages/{$foreignMessage->id}")->assertNotFound();
        $this->api()->patchJson("/api/v1/mailboxes/{$foreignMailbox->id}/messages/{$foreignMessage->id}/read", ['read' => true])->assertNotFound();
    }

    public function test_marks_a_message_thread_read_and_unread(): void
    {
        $mailbox = $this->mailbox();
        $message = $this->inbound($mailbox, ['is_read' => false]);

        $this->api()->patchJson("/api/v1/mailboxes/{$mailbox->id}/messages/{$message->id}/read", ['read' => true])
            ->assertOk()->assertJsonPath('read', true);
        $this->assertTrue($message->thread->fresh()->is_read);

        $this->api()->patchJson("/api/v1/mailboxes/{$mailbox->id}/messages/{$message->uuid}/read", ['read' => false])
            ->assertOk()->assertJsonPath('read', false);
        $this->assertFalse($message->thread->fresh()->is_read);

        $this->api()->patchJson("/api/v1/mailboxes/{$mailbox->id}/messages/{$message->id}/read", [])
            ->assertUnprocessable()->assertJsonValidationErrors('read');
    }

    public function test_inbox_threads_can_be_filtered_by_mailbox(): void
    {
        $mailbox = $this->mailbox();
        $other = $this->mailbox(null, 'sales@acme.test');
        $this->inbound($mailbox);
        $this->inbound($other);

        $this->api()->getJson('/api/v1/inbox/threads?mailbox_id='.$mailbox->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.mailbox_id', $mailbox->id);
        $this->api()->getJson('/api/v1/inbox/threads')->assertOk()->assertJsonCount(2, 'data');
    }
}
