<?php

namespace Tests\Feature;

use App\Jobs\SendBroadcast;
use App\Jobs\SendBroadcastRecipient;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Contact;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\Suppression;
use App\Models\User;
use App\Services\BroadcastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BroadcastSendingTest extends TestCase
{
    use RefreshDatabase;

    private function member(): array
    {
        $user = User::factory()->create();
        $provider = MailProvider::factory()->create(['key' => 'array_'.uniqid(), 'driver' => 'array', 'status' => 'active']);
        $org = Organization::factory()->create([
            'default_provider' => 'array',
            'mail_provider_id' => $provider->id,
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $org];
    }

    private function as(User $user, Organization $org): static
    {
        return $this->actingAs($user)->withSession(['current_organization_id' => $org->id]);
    }

    private function contacts(Organization $org): void
    {
        Contact::factory()->create(['organization_id' => $org->id, 'email' => 'ann@example.com', 'first_name' => 'Ann']);
        Contact::factory()->create(['organization_id' => $org->id, 'email' => 'bob@example.com']);
        Contact::factory()->create(['organization_id' => $org->id, 'email' => 'gone@example.com', 'unsubscribed_at' => now()]);
        Contact::factory()->create(['organization_id' => $org->id, 'email' => 'blocked@example.com']);
        Suppression::query()->create(['organization_id' => $org->id, 'email' => 'blocked@example.com', 'source' => 'manual']);
    }

    public function test_send_now_queues_the_broadcast_instead_of_sending_inline(): void
    {
        Queue::fake();
        [$user, $org] = $this->member();

        $this->as($user, $org)->post(route('broadcasts.store'), [
            'name' => 'Launch',
            'subject' => 'We launched',
            'html' => '<p>Hello</p>',
            'segment' => 'all',
            'from' => 'news@acme.test',
            'send_now' => true,
        ])->assertRedirect();

        $broadcast = Broadcast::query()->firstOrFail();
        $this->assertSame('queued', $broadcast->status);
        $this->assertSame('all', $broadcast->audience);
        $this->assertSame('news@acme.test', $broadcast->from);
        $this->assertNotNull($broadcast->queued_at);
        $this->assertSame(0, Message::query()->count());
        Queue::assertPushed(SendBroadcast::class, fn ($job) => $job->broadcastId === $broadcast->id);
    }

    public function test_pipeline_fans_out_skips_unsubscribed_and_suppressed_and_finishes(): void
    {
        [$user, $org] = $this->member();
        $this->contacts($org);
        Contact::factory()->create(['organization_id' => Organization::factory()->create()->id, 'email' => 'other-org@example.com']);

        $this->as($user, $org)->post(route('broadcasts.store'), [
            'name' => 'Launch',
            'subject' => 'Hi {{first_name}}',
            'html' => '<html><body><p>Hi {{first_name}}</p></body></html>',
            'segment' => 'all',
            'from' => 'news@acme.test',
            'send_now' => true,
        ])->assertRedirect();

        $broadcast = Broadcast::query()->firstOrFail();
        $this->assertSame('sent', $broadcast->status);
        $this->assertNotNull($broadcast->sent_at);
        $this->assertSame(4, $broadcast->recipient_count);

        $statuses = BroadcastRecipient::query()->pluck('status', 'email')->all();
        $this->assertSame([
            'ann@example.com' => 'sent',
            'blocked@example.com' => 'skipped',
            'bob@example.com' => 'sent',
            'gone@example.com' => 'skipped',
        ], collect($statuses)->sortKeys()->all());

        $messages = Message::query()->where('direction', 'outbound')->get();
        $this->assertCount(2, $messages);
        $ann = $messages->first(fn ($m) => $m->to === ['ann@example.com']);
        $this->assertStringContainsString('Hi Ann', $ann->html_body);
        $this->assertStringContainsString('/unsubscribe/', $ann->html_body);
        $this->assertStringContainsString('signature=', $ann->headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $ann->headers['List-Unsubscribe-Post']);
        $this->assertSame(['broadcast:'.$broadcast->id], $ann->tags);

        $counts = app(BroadcastService::class)->counts($broadcast);
        $this->assertSame(4, $counts['recipients']);
        $this->assertSame(2, $counts['sent']);
        $this->assertSame(2, $counts['skipped']);
        $this->assertSame(0, $counts['pending']);
    }

    public function test_segment_audience_only_targets_segment_members(): void
    {
        [$user, $org] = $this->member();
        $this->contacts($org);
        $segment = Segment::factory()->create(['organization_id' => $org->id]);
        $segment->contacts()->attach(Contact::query()->where('email', 'bob@example.com')->value('id'));

        $this->as($user, $org)->post(route('broadcasts.store'), [
            'name' => 'VIP', 'subject' => 'VIP', 'html' => '<p>VIP</p>',
            'segment' => (string) $segment->id, 'send_now' => true,
        ]);

        $this->assertSame(['bob@example.com'], BroadcastRecipient::query()->pluck('email')->all());
    }

    public function test_placeholder_controls_where_the_unsubscribe_link_goes(): void
    {
        [$user, $org] = $this->member();
        Contact::factory()->create(['organization_id' => $org->id, 'email' => 'ann@example.com']);

        $this->as($user, $org)->post(route('broadcasts.store'), [
            'name' => 'P', 'subject' => 'P', 'send_now' => true,
            'html' => '<p>Body</p><a href="{{unsubscribe_url}}">Leave</a>',
        ]);

        $html = Message::query()->value('html_body');
        $this->assertStringNotContainsString('{{unsubscribe_url}}', $html);
        $this->assertSame(1, substr_count($html, '/unsubscribe/'));
        $this->assertStringNotContainsString('You are receiving this', $html);
    }

    public function test_unsubscribe_link_confirms_then_unsubscribes_and_suppresses(): void
    {
        [$user, $org] = $this->member();
        Contact::factory()->create(['organization_id' => $org->id, 'email' => 'ann@example.com']);
        $this->as($user, $org)->post(route('broadcasts.store'), [
            'name' => 'U', 'subject' => 'U', 'html' => '<p>x</p>', 'send_now' => true,
        ]);
        auth()->logout();

        $recipient = BroadcastRecipient::query()->firstOrFail();
        $url = app(BroadcastService::class)->unsubscribeUrl($recipient);

        // GET only shows a confirm button, so link scanners don't unsubscribe.
        $this->get($url)->assertOk()->assertSee('Unsubscribe?')->assertSee('ann@example.com');
        $this->assertNull($recipient->fresh()->unsubscribed_at);

        // One-click POST (no CSRF token), as mail providers send it.
        $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('unsubscribed');

        $this->assertNotNull($recipient->fresh()->unsubscribed_at);
        $contact = Contact::query()->where('email', 'ann@example.com')->first();
        $this->assertNotNull($contact->unsubscribed_at);
        $this->assertSame('unsubscribed', $contact->meta['status']);
        $this->assertDatabaseHas('suppressions', ['organization_id' => $org->id, 'email' => 'ann@example.com', 'source' => 'unsubscribe']);

        // Repeating is harmless.
        $this->post($url)->assertOk();
        $this->assertSame(1, Suppression::query()->count());

        // The next broadcast skips them.
        $this->as($user, $org)->post(route('broadcasts.store'), [
            'name' => 'U2', 'subject' => 'U2', 'html' => '<p>x</p>', 'send_now' => true,
        ]);
        $this->assertSame('skipped', BroadcastRecipient::query()->latest('id')->value('status'));
    }

    public function test_tampered_or_unsigned_unsubscribe_links_are_rejected(): void
    {
        [$user, $org] = $this->member();
        Contact::factory()->create(['organization_id' => $org->id, 'email' => 'ann@example.com']);
        Contact::factory()->create(['organization_id' => $org->id, 'email' => 'bob@example.com']);
        $this->as($user, $org)->post(route('broadcasts.store'), [
            'name' => 'U', 'subject' => 'U', 'html' => '<p>x</p>', 'send_now' => true,
        ]);
        auth()->logout();
        $recipient = BroadcastRecipient::query()->orderBy('id')->firstOrFail();

        $this->get('/unsubscribe/'.$recipient->id)->assertForbidden();
        $this->post('/unsubscribe/'.$recipient->id)->assertForbidden();

        $url = app(BroadcastService::class)->unsubscribeUrl($recipient);
        $this->post(str_replace('/unsubscribe/'.$recipient->id, '/unsubscribe/'.($recipient->id + 1), $url))->assertForbidden();
        $this->assertNull($recipient->fresh()->unsubscribed_at);
    }

    public function test_delivery_counts_follow_message_events_and_show_page_exposes_them(): void
    {
        [$user, $org] = $this->member();
        $this->contacts($org);
        $this->as($user, $org)->post(route('broadcasts.store'), [
            'name' => 'C', 'subject' => 'C', 'html' => '<p>x</p>', 'send_now' => true,
        ]);
        $broadcast = Broadcast::query()->firstOrFail();

        $messages = Message::query()->orderBy('id')->get();
        $messages[0]->update(['status' => 'delivered', 'meta' => ['first_opened_at' => now()->toIso8601String()]]);
        $messages[1]->update(['status' => 'bounced']);

        $this->as($user, $org)->get(route('broadcasts.show', $broadcast))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Broadcasts/Show')
                ->where('counts.recipients', 4)
                ->where('counts.sent', 2)
                ->where('counts.delivered', 1)
                ->where('counts.bounced', 1)
                ->where('counts.opened', 1)
                ->where('counts.skipped', 2)
                ->where('broadcast.recipients', 4)
                ->has('recipients', 4));
    }

    public function test_drafts_can_be_sent_later_and_sent_broadcasts_cannot_be_resent(): void
    {
        Queue::fake();
        [$user, $org] = $this->member();

        $this->as($user, $org)->post(route('broadcasts.store'), [
            'name' => 'D', 'subject' => 'D', 'html' => '<p>x</p>', 'send_now' => false,
        ]);
        $broadcast = Broadcast::query()->firstOrFail();
        $this->assertSame('draft', $broadcast->status);
        Queue::assertNothingPushed();

        $this->as($user, $org)->post(route('broadcasts.send', $broadcast))->assertRedirect()->assertSessionHas('success');
        $this->assertSame('queued', $broadcast->fresh()->status);
        Queue::assertPushed(SendBroadcast::class, 1);

        $this->as($user, $org)->post(route('broadcasts.send', $broadcast))->assertSessionHas('error');
        Queue::assertPushed(SendBroadcast::class, 1);

        $foreign = Broadcast::factory()->create(['organization_id' => Organization::factory()->create()->id, 'status' => 'draft']);
        $this->as($user, $org)->post(route('broadcasts.send', $foreign))->assertNotFound();
    }

    public function test_empty_audience_finishes_immediately_and_throttle_applies_on_real_queues(): void
    {
        [$user, $org] = $this->member();
        $this->as($user, $org)->post(route('broadcasts.store'), [
            'name' => 'E', 'subject' => 'E', 'html' => '<p>x</p>', 'send_now' => true,
        ]);
        $this->assertSame('sent', Broadcast::query()->value('status'));
        $this->assertSame(0, Broadcast::query()->value('recipient_count'));

        $this->assertSame([], (new SendBroadcastRecipient(1))->middleware());
        config(['queue.default' => 'database']);
        $this->assertCount(1, (new SendBroadcastRecipient(1))->middleware());
    }
}
