<?php

namespace Tests\Feature;

use App\Jobs\FanOutGroupMessage;
use App\Models\Attachment;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\GroupAddress;
use App\Models\GroupAddressMember;
use App\Models\Mailbox;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Suppression;
use App\Models\Thread;
use App\Models\User;
use App\Services\BroadcastService;
use App\Services\EmailService;
use App\Services\GroupAddressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SignaturesAndGroupsTest extends TestCase
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
        Domain::factory()->verified()->create(['organization_id' => $org->id, 'name' => 'acme.test']);

        return [$user, $org];
    }

    private function as(User $user, Organization $org): static
    {
        return $this->actingAs($user)->withSession(['current_organization_id' => $org->id]);
    }

    private function enableSignature(Organization $org, string $html = '<p><strong>Ade</strong> · Acme</p>', array $extra = []): void
    {
        $org->forceFill(['settings' => array_merge($org->settings ?? [], [
            'signature' => array_merge(['enabled' => true, 'html' => $html], $extra),
        ])])->save();
    }

    /** A thread with one inbound customer message. */
    private function thread(Organization $org): array
    {
        $mailbox = Mailbox::factory()->create(['organization_id' => $org->id, 'email' => 'support@acme.test']);
        $thread = Thread::factory()->create(['organization_id' => $org->id, 'mailbox_id' => $mailbox->id, 'subject' => 'Help']);
        $inbound = Message::factory()->create([
            'organization_id' => $org->id,
            'thread_id' => $thread->id,
            'direction' => 'inbound',
            'status' => 'received',
            'from_email' => 'customer@example.com',
            'from_name' => 'Cara Customer',
            'to' => ['support@acme.test'],
            'subject' => 'Help',
            'html_body' => '<p>My order is late</p>',
            'text_body' => 'My order is late',
            'message_id_header' => '<in-1@example.com>',
        ]);

        return [$thread, $inbound, $mailbox];
    }

    private function group(Organization $org, string $email = 'staff@acme.test', array $members = ['ann@example.com', 'bob@example.com']): GroupAddress
    {
        $group = GroupAddress::query()->create(['organization_id' => $org->id, 'email' => $email, 'name' => 'Staff']);
        foreach ($members as $member) {
            GroupAddressMember::query()->create(['group_address_id' => $group->id, 'email' => $member]);
        }

        return $group;
    }

    // ---- Signatures -------------------------------------------------------

    public function test_settings_saves_signature_and_mailbox_override_cleaned(): void
    {
        [$user, $org] = $this->member();
        $mailbox = Mailbox::factory()->create(['organization_id' => $org->id, 'email' => 'sales@acme.test']);

        $this->as($user, $org)->put(route('settings.update'), [
            'signature' => ['enabled' => true, 'html' => '<p>Ade<script>alert(1)</script></p>', 'api' => false, 'broadcasts' => true],
            'mailbox_signatures' => [['id' => $mailbox->id, 'signature' => '<p>Sales team</p>']],
        ])->assertSessionHas('success');

        $saved = $org->fresh()->settings['signature'];
        $this->assertTrue($saved['enabled']);
        $this->assertStringNotContainsString('script', $saved['html']);
        $this->assertStringContainsString('Ade', $saved['html']);
        $this->assertSame('<p>Sales team</p>', $mailbox->fresh()->signature);

        $this->as($user, $org)->get('/settings/signature')->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Index')
            ->where('tab', 'signature')
            ->where('settings.signature.enabled', true)
            ->where('mailboxes.0.email', 'sales@acme.test'));
    }

    public function test_compose_appends_signature_to_html_and_text(): void
    {
        [$user, $org] = $this->member();
        $this->enableSignature($org);

        $this->as($user, $org)->post(route('emails.store'), [
            'from' => 'hello@acme.test',
            'to' => 'cara@example.com',
            'subject' => 'Hi',
            'html' => '<p>Hello there</p>',
        ])->assertRedirect();

        $message = Message::query()->where('direction', 'outbound')->firstOrFail();
        $this->assertStringContainsString('<p>Hello there</p>', $message->html_body);
        $this->assertStringContainsString('data-maildesk-signature', $message->html_body);
        $this->assertStringContainsString('<strong>Ade</strong>', $message->html_body);
        $this->assertStringContainsString("\n-- \nAde · Acme", $message->text_body);
    }

    public function test_reply_uses_mailbox_signature_over_workspace_signature(): void
    {
        [$user, $org] = $this->member();
        $this->enableSignature($org);
        [$thread, , $mailbox] = $this->thread($org);
        $mailbox->update(['signature' => '<p>Support desk</p>']);

        $this->as($user, $org)->post(route('inbox.reply', $thread->id), ['html' => '<p>On its way</p>'])
            ->assertSessionHas('success');

        $reply = Message::query()->where('direction', 'outbound')->firstOrFail();
        $this->assertStringContainsString('Support desk', $reply->html_body);
        $this->assertStringNotContainsString('Ade', $reply->html_body);
    }

    public function test_mailbox_signature_applies_when_workspace_signature_is_disabled(): void
    {
        [$user, $org] = $this->member();
        $this->enableSignature($org, extra: ['enabled' => false]);
        [$thread, , $mailbox] = $this->thread($org);
        $mailbox->update(['signature' => '<p>Desk only</p>']);

        $this->as($user, $org)->post(route('inbox.reply', $thread->id), ['html' => '<p>Ok</p>'])
            ->assertSessionHas('success');

        $reply = Message::query()->where('direction', 'outbound')->firstOrFail();
        $this->assertStringContainsString('data-maildesk-signature', $reply->html_body);
        $this->assertStringContainsString('Desk only', $reply->html_body);
        $this->assertStringNotContainsString('Ade', $reply->html_body);
    }

    public function test_mailbox_member_can_edit_own_signature_but_not_settings(): void
    {
        $user = User::factory()->create(['email' => 'desk@acme.test']);
        $provider = MailProvider::query()->where('key', 'resend')->first()
            ?? MailProvider::factory()->create(['key' => 'resend', 'driver' => 'resend', 'status' => 'active']);
        $org = Organization::factory()->create([
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
        ]);
        $org->users()->attach($user->id, ['role' => 'member']);
        Domain::factory()->verified()->create(['organization_id' => $org->id, 'name' => 'acme.test']);
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'email' => 'desk@acme.test',
            'inbox' => true,
            'status' => 'active',
        ]);

        $this->as($user, $org)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Profile/Edit')
                ->where('mailbox.email', 'desk@acme.test'));

        $this->as($user, $org)
            ->get(route('mailbox.signature'))
            ->assertRedirect(route('profile.edit'));

        $this->as($user, $org)
            ->put(route('mailbox.signature.update'), [
                'signature' => '<p>My name<script>x</script></p>',
            ])
            ->assertSessionHas('success');

        $this->assertStringContainsString('My name', (string) $mailbox->fresh()->signature);
        $this->assertStringNotContainsString('script', (string) $mailbox->fresh()->signature);

        $this->as($user, $org)->get(route('settings', 'signature'))->assertForbidden();
    }

    public function test_owner_without_mailbox_is_redirected_to_settings_signature(): void
    {
        [$user, $org] = $this->member();
        // Owners are team managers and often have no linked mailbox.
        $org->users()->updateExistingPivot($user->id, ['role' => 'owner']);
        $this->assertNull($org->mailboxes()->where('user_id', $user->id)->first());

        $this->as($user, $org)
            ->get(route('mailbox.signature'))
            ->assertRedirect(route('settings', 'signature'));

        $this->as($user, $org)
            ->get(route('settings', 'signature'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Index')
                ->where('tab', 'signature'));
    }

    public function test_disabled_signature_is_not_added(): void
    {
        [$user, $org] = $this->member();
        $this->enableSignature($org, extra: ['enabled' => false]);
        [$thread] = $this->thread($org);

        $this->as($user, $org)->post(route('inbox.reply', $thread->id), ['html' => '<p>Ok</p>']);

        $this->assertStringNotContainsString('data-maildesk-signature', Message::query()->where('direction', 'outbound')->value('html_body'));
    }

    public function test_api_sends_skip_signature_unless_asked_or_enabled_for_api(): void
    {
        [, $org] = $this->member();
        $this->enableSignature($org);
        $emails = app(EmailService::class);

        $plain = $emails->send($org, ['from' => 'a@acme.test', 'to' => 'x@example.com', 'subject' => 'S', 'html' => '<p>Body</p>']);
        $this->assertStringNotContainsString('data-maildesk-signature', $plain->html_body);

        $signed = $emails->send($org, ['from' => 'a@acme.test', 'to' => 'x@example.com', 'subject' => 'S', 'html' => '<p>Body</p>', 'signature' => true]);
        $this->assertStringContainsString('data-maildesk-signature', $signed->html_body);
    }

    public function test_broadcast_signature_sits_above_unsubscribe_footer_and_skips_inbox(): void
    {
        [, $org] = $this->member();
        $this->enableSignature($org);
        Contact::factory()->create(['organization_id' => $org->id, 'email' => 'ann@example.com']);
        $broadcast = Broadcast::query()->create([
            'organization_id' => $org->id, 'name' => 'News', 'subject' => 'News',
            'html' => '<p>Big news</p>', 'from' => 'news@acme.test', 'status' => 'draft',
        ]);

        app(BroadcastService::class)->queue($broadcast);

        $message = Message::query()->where('direction', 'outbound')->firstOrFail();
        $html = $message->html_body;
        $this->assertLessThan(strpos($html, 'Unsubscribe'), strpos($html, 'data-maildesk-signature'));
        $this->assertNull($message->thread_id);
        $this->assertSame(0, Thread::query()->where('organization_id', $org->id)->count());
    }

    // ---- Forward ----------------------------------------------------------

    public function test_forward_sends_note_signature_original_and_attachments_on_the_thread(): void
    {
        Storage::fake('local');
        [$user, $org] = $this->member();
        $this->enableSignature($org);
        [$thread, $inbound] = $this->thread($org);
        Storage::disk('local')->put('attachments/'.$org->id.'/invoice.pdf', 'pdf');
        Attachment::query()->create([
            'message_id' => $inbound->id, 'filename' => 'invoice.pdf', 'content_type' => 'application/pdf',
            'size' => 3, 'disk' => 'local', 'path' => 'attachments/'.$org->id.'/invoice.pdf',
        ]);

        $this->as($user, $org)->post(route('inbox.forward', $thread->id), [
            'to' => 'finance@example.com',
            'html' => '<p>Can you check this?</p>',
        ])->assertSessionHas('success');

        $forward = Message::query()->where('direction', 'outbound')->with('attachments')->firstOrFail();
        $this->assertSame('Fwd: Help', $forward->subject);
        $this->assertSame(['finance@example.com'], $forward->to);
        $this->assertSame($thread->id, $forward->thread_id);
        $html = $forward->html_body;
        $this->assertLessThan(strpos($html, 'data-maildesk-signature'), strpos($html, 'Can you check this?'));
        $this->assertLessThan(strpos($html, 'Forwarded message'), strpos($html, 'data-maildesk-signature'));
        $this->assertStringContainsString('My order is late', $html);
        $this->assertStringContainsString('Cara Customer', $html);
        $this->assertSame(1, substr_count($html, 'data-maildesk-signature'));
        $this->assertSame(['invoice.pdf'], $forward->attachments->pluck('filename')->all());
    }

    public function test_forward_requires_a_recipient(): void
    {
        [$user, $org] = $this->member();
        [$thread] = $this->thread($org);

        $this->as($user, $org)->post(route('inbox.forward', $thread->id), ['to' => ''])
            ->assertSessionHasErrors('to');
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
    }

    // ---- Groups -----------------------------------------------------------

    public function test_group_crud_validates_domain_and_members(): void
    {
        [$user, $org] = $this->member();
        Mailbox::factory()->create(['organization_id' => $org->id, 'email' => 'support@acme.test']);

        $this->as($user, $org)->post(route('groups.store'), ['name' => 'X', 'email' => 'team@other.test'])
            ->assertSessionHasErrors('email');
        $this->as($user, $org)->post(route('groups.store'), ['name' => 'X', 'email' => 'support@acme.test'])
            ->assertSessionHasErrors('email');

        $this->as($user, $org)->post(route('groups.store'), [
            'name' => 'Staff', 'email' => 'Staff@Acme.test',
            'members' => "ann@example.com\n\"Bob B\" <bob@example.com>, ann@example.com",
        ])->assertSessionHas('success');

        $group = GroupAddress::query()->firstOrFail();
        $this->assertSame('staff@acme.test', $group->email);
        $this->assertSame(['ann@example.com', 'bob@example.com'], $group->members->pluck('email')->all());
        $this->assertSame('Bob B', $group->members->firstWhere('email', 'bob@example.com')->name);

        // Groups can't contain groups (or themselves).
        $this->group($org, 'ops@acme.test', []);
        $this->as($user, $org)->post(route('groups.members.store', $group->id), ['members' => 'ops@acme.test, staff@acme.test, cy@example.com']);
        $this->assertSame(['ann@example.com', 'bob@example.com', 'cy@example.com'], $group->fresh()->members->pluck('email')->all());

        $member = $group->members()->where('email', 'cy@example.com')->first();
        $this->as($user, $org)->delete(route('groups.members.destroy', [$group->id, $member->id]))->assertSessionHas('success');
        $this->assertSame(2, $group->members()->count());

        $this->as($user, $org)->get(route('groups'))->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Index')
            ->has('groups', 2)
            ->where('domains', ['acme.test']));

        $this->as($user, $org)->delete(route('groups.destroy', $group->id))->assertSessionHas('success');
        $this->assertSame(0, GroupAddressMember::query()->where('group_address_id', $group->id)->count());
    }

    public function test_other_workspaces_cannot_touch_a_group(): void
    {
        [$user, $org] = $this->member();
        [, $other] = $this->member();
        $group = $this->group($other, 'staff@other.test');

        $this->as($user, $org)->delete(route('groups.destroy', $group->id))->assertNotFound();
        $this->assertNotNull($group->fresh());
    }

    public function test_sending_to_a_group_expands_to_members_and_drops_suppressed(): void
    {
        [$user, $org] = $this->member();
        $this->group($org, 'staff@acme.test', ['ann@example.com', 'bob@example.com', 'blocked@example.com', 'cara@example.com']);
        Suppression::query()->create(['organization_id' => $org->id, 'email' => 'blocked@example.com', 'source' => 'manual']);

        $this->as($user, $org)->post(route('emails.store'), [
            'from' => 'hello@acme.test',
            'to' => 'staff@acme.test',
            'cc' => 'cara@example.com',
            'subject' => 'All hands',
            'html' => '<p>Friday</p>',
        ])->assertRedirect();

        $message = Message::query()->where('direction', 'outbound')->firstOrFail();
        $this->assertSame('sent', $message->status);
        $this->assertSame(['ann@example.com', 'bob@example.com', 'cara@example.com'], $message->to);
        $this->assertNull($message->cc);
    }

    public function test_sending_to_an_empty_group_is_rejected(): void
    {
        [$user, $org] = $this->member();
        $this->group($org, 'empty@acme.test', []);

        $this->as($user, $org)->post(route('emails.store'), [
            'from' => 'hello@acme.test', 'to' => 'empty@acme.test', 'subject' => 'Hi', 'html' => '<p>x</p>',
        ])->assertSessionHasErrors('to');
    }

    public function test_inbound_mail_to_a_group_fans_out_through_the_queue(): void
    {
        Queue::fake();
        config(['maildesk.inbound.generic_secret' => 'generic-secret']);
        [, $org] = $this->member();
        $group = $this->group($org, 'staff@acme.test', ['ann@example.com', 'bob@example.com', 'jane@example.com', 'ops@acme.test']);
        $this->group($org, 'ops@acme.test', ['zed@example.com']);

        $this->withHeader('X-MailDesk-Inbound-Secret', 'generic-secret')
            ->postJson('/api/v1/inbound/generic', [
                'from' => 'Jane <jane@example.com>',
                'to' => ['staff@acme.test'],
                'subject' => 'Lunch?',
                'html' => '<p>Pizza at 1</p>',
                'message_id' => '<lunch@example.com>',
            ])->assertSuccessful();

        $inbound = Message::query()->where('direction', 'inbound')->firstOrFail();
        // The sender and nested group addresses are skipped.
        Queue::assertPushed(FanOutGroupMessage::class, 2);
        Queue::assertPushed(FanOutGroupMessage::class, fn ($job) => $job->memberEmail === 'ann@example.com' && $job->groupId === $group->id);
        $this->assertSame(['ann@example.com', 'bob@example.com'], data_get($inbound->meta, 'group_fanout.0.members'));

        $thread = Thread::query()->with(['messages.attachments'])->find($inbound->thread_id);
        $this->assertSame('staff@acme.test', $thread->toWorkspaceArray()['messages'][0]['fanout'][0]['email']);
    }

    public function test_fan_out_copy_goes_via_the_group_with_reply_to_sender_and_is_idempotent(): void
    {
        [, $org] = $this->member();
        $group = $this->group($org);
        $inbound = Message::factory()->create([
            'organization_id' => $org->id,
            'direction' => 'inbound',
            'status' => 'received',
            'from_email' => 'jane@example.com',
            'from_name' => 'Jane',
            'to' => ['staff@acme.test'],
            'subject' => 'Lunch?',
            'html_body' => '<p>Pizza</p>',
        ]);

        (new FanOutGroupMessage($inbound->id, $group->id, 'ann@example.com'))->handle(app(GroupAddressService::class), app(EmailService::class));
        (new FanOutGroupMessage($inbound->id, $group->id, 'ann@example.com'))->handle(app(GroupAddressService::class), app(EmailService::class));

        $copies = Message::query()->where('direction', 'outbound')->get();
        $this->assertCount(1, $copies);
        $copy = $copies->first();
        $this->assertSame('staff@acme.test', $copy->from_email);
        $this->assertSame('Jane via Staff', $copy->from_name);
        $this->assertSame(['jane@example.com'], $copy->reply_to);
        $this->assertSame('staff@acme.test', $copy->headers['X-MailDesk-Group']);
        $this->assertNull($copy->thread_id);
        $this->assertSame($inbound->id, data_get($copy->meta, 'fanout_of'));
    }

    public function test_inbound_copy_carrying_the_group_header_is_not_fanned_out_again(): void
    {
        Queue::fake();
        config(['maildesk.inbound.generic_secret' => 'generic-secret']);
        [, $org] = $this->member();
        $this->group($org);

        $this->withHeader('X-MailDesk-Inbound-Secret', 'generic-secret')
            ->postJson('/api/v1/inbound/generic', [
                'from' => 'Staff <staff@acme.test>',
                'to' => ['staff@acme.test'],
                'subject' => 'Loop',
                'text' => 'loop',
                'headers' => ['X-MailDesk-Group' => 'staff@acme.test'],
                'message_id' => '<loop@example.com>',
            ])->assertSuccessful();

        Queue::assertNotPushed(FanOutGroupMessage::class);
    }

    public function test_group_can_be_a_broadcast_audience(): void
    {
        [$user, $org] = $this->member();
        $group = $this->group($org, 'staff@acme.test', ['ann@example.com', 'bob@example.com', 'gone@example.com']);
        $ann = Contact::factory()->create(['organization_id' => $org->id, 'email' => 'ann@example.com']);
        Contact::factory()->create(['organization_id' => $org->id, 'email' => 'gone@example.com', 'unsubscribed_at' => now()]);
        Contact::factory()->create(['organization_id' => $org->id, 'email' => 'not-in-group@example.com']);

        $this->as($user, $org)->get(route('broadcasts.create', ['segment' => 'group:'.$group->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected', 'group:'.$group->id)
                ->where('segments', fn ($segments) => collect($segments)->contains(fn ($s) => $s['id'] === 'group:'.$group->id && $s['count'] === 3)));

        $this->as($user, $org)->post(route('broadcasts.store'), [
            'name' => 'Staff update', 'subject' => 'Hi {{first_name}}', 'html' => '<p>Update</p>',
            'segment' => 'group:'.$group->id, 'from' => 'news@acme.test', 'send_now' => true,
        ])->assertRedirect();

        $broadcast = Broadcast::query()->firstOrFail();
        $this->assertSame('sent', $broadcast->status);
        $this->assertStringContainsString('Staff', $broadcast->audienceLabel());
        $rows = BroadcastRecipient::query()->orderBy('email')->get();
        $this->assertSame(['ann@example.com', 'bob@example.com', 'gone@example.com'], $rows->pluck('email')->all());
        $this->assertSame(['sent', 'sent', 'skipped'], $rows->pluck('status')->all());
        $this->assertSame($ann->id, $rows->first()->contact_id);
        $this->assertNull($rows[1]->contact_id);
    }

    public function test_broadcast_rejects_a_group_from_another_workspace(): void
    {
        [$user, $org] = $this->member();
        [, $other] = $this->member();
        $group = $this->group($other, 'staff@other.test');

        $this->as($user, $org)->post(route('broadcasts.store'), [
            'name' => 'X', 'subject' => 'X', 'html' => '<p>X</p>', 'segment' => 'group:'.$group->id,
        ])->assertSessionHasErrors('segment');
    }

    // ---- Unsubscribe branding --------------------------------------------

    public function test_unsubscribe_page_uses_workspace_branding(): void
    {
        [, $org] = $this->member();
        $org->forceFill(['settings' => ['unsubscribe' => [
            'brand' => 'Acme Weekly', 'headline' => 'Sorry to see you go',
            'accent' => '#ff0055', 'footer' => 'Changed your mind? Reply to any email.',
            'accentBad' => 'x',
        ]]])->save();
        $contact = Contact::factory()->create(['organization_id' => $org->id, 'email' => 'ann@example.com']);
        $broadcast = Broadcast::query()->create(['organization_id' => $org->id, 'name' => 'N', 'subject' => 'N', 'html' => '<p>x</p>', 'status' => 'sent']);
        $recipient = BroadcastRecipient::query()->create(['broadcast_id' => $broadcast->id, 'contact_id' => $contact->id, 'email' => 'ann@example.com', 'status' => 'sent']);
        $url = app(BroadcastService::class)->unsubscribeUrl($recipient);

        $this->get($url)->assertOk()->assertSee('Acme Weekly')->assertSee('#ff0055', false);
        $this->post($url)->assertOk()->assertSee('Sorry to see you go')->assertSee('Changed your mind?');
    }

    public function test_unsubscribe_page_ignores_an_unsafe_accent(): void
    {
        [, $org] = $this->member();
        $org->forceFill(['settings' => ['unsubscribe' => ['accent' => 'red;}body{display:none']]])->save();
        $broadcast = Broadcast::query()->create(['organization_id' => $org->id, 'name' => 'N', 'subject' => 'N', 'html' => '<p>x</p>', 'status' => 'sent']);
        $recipient = BroadcastRecipient::query()->create(['broadcast_id' => $broadcast->id, 'email' => 'ann@example.com', 'status' => 'sent']);

        $this->get(app(BroadcastService::class)->unsubscribeUrl($recipient))
            ->assertOk()->assertDontSee('display:none', false)->assertSee('#22d3ee', false);
    }
}
