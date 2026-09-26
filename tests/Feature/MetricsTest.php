<?php

namespace Tests\Feature;

use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Domain;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MetricsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->org = Organization::factory()->create();
        $this->org->users()->attach($this->user->id, ['role' => 'owner']);
        Domain::factory()->verified()->create(['organization_id' => $this->org->id, 'name' => 'acme.test']);
        Domain::factory()->verified()->create(['organization_id' => $this->org->id, 'name' => 'other.test']);
    }

    private function message(array $attributes = []): Message
    {
        return Message::factory()->create(array_merge([
            'organization_id' => $this->org->id,
            'direction' => 'outbound',
            'from_email' => 'hello@acme.test',
            'status' => 'sent',
            'meta' => null,
        ], $attributes));
    }

    private function metrics(array $query = [])
    {
        return $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->get(route('metrics', $query))
            ->assertOk();
    }

    public function test_delivered_counts_only_delivery_events_and_complaint_rate_is_real(): void
    {
        $this->message(['status' => 'sent']);                     // accepted, not delivered
        $this->message(['status' => 'delivered', 'meta' => ['delivered_at' => now()->toIso8601String()]]);
        $this->message(['status' => 'delivered']);
        $this->message(['status' => 'complained', 'meta' => ['delivered_at' => now()->toIso8601String(), 'complained_at' => now()->toIso8601String()]]);
        $this->message(['status' => 'bounced']);
        $this->message(['status' => 'failed']);
        $this->message(['status' => 'queued']);
        $this->message(['direction' => 'inbound', 'status' => 'received']);

        $this->metrics()->assertInertia(fn (Assert $page) => $page
            ->component('Metrics/Index')
            ->where('stats.emails', 7)
            ->where('stats.sent', 5)
            ->where('stats.delivered', 3)
            ->where('stats.bounced', 1)
            ->where('stats.complained', 1)
            ->where('stats.failed', 1)
            ->where('stats.deliverability', 60)
            ->where('stats.bounce_rate', 20)
            ->where('stats.complaint_rate', 33.3)
            ->where('complaintSeries.14', 1)
            ->where('bounceSeries.14', 1)
            ->where('series.14.delivered', 3)
            ->where('series.14.pending', 2));
    }

    public function test_date_range_filter(): void
    {
        $this->message(['status' => 'delivered']);
        $old = $this->message(['status' => 'delivered']);
        $old->forceFill(['created_at' => now()->subDays(20)])->save();

        $this->metrics()->assertInertia(fn (Assert $page) => $page
            ->where('stats.delivered', 1)->where('filters.days', 15)->has('series', 15));
        $this->metrics(['days' => 30])->assertInertia(fn (Assert $page) => $page
            ->where('stats.delivered', 2)->where('filters.days', 30)->has('series', 30));
        $this->metrics(['days' => 7])->assertInertia(fn (Assert $page) => $page->has('series', 7));
        $this->metrics(['days' => 9999])->assertInertia(fn (Assert $page) => $page->where('filters.days', 15));
    }

    public function test_domain_and_tag_filters(): void
    {
        $this->message(['status' => 'delivered', 'from_email' => 'a@acme.test', 'tags' => ['welcome']]);
        $this->message(['status' => 'delivered', 'from_email' => 'b@other.test', 'tags' => ['receipt']]);
        $this->message(['status' => 'bounced', 'from_email' => 'c@other.test', 'tags' => ['welcome']]);

        $this->metrics()->assertInertia(fn (Assert $page) => $page
            ->where('stats.sent', 3)
            ->where('domainOptions', ['acme.test', 'other.test'])
            ->where('tagOptions', ['receipt', 'welcome']));

        $this->metrics(['domain' => 'other.test'])->assertInertia(fn (Assert $page) => $page
            ->where('stats.sent', 2)->where('stats.bounced', 1)->where('filters.domain', 'other.test'));

        $this->metrics(['tag' => 'welcome'])->assertInertia(fn (Assert $page) => $page
            ->where('stats.sent', 2)->where('stats.delivered', 1));

        $this->metrics(['domain' => 'other.test', 'tag' => 'welcome'])->assertInertia(fn (Assert $page) => $page
            ->where('stats.sent', 1)->where('stats.delivered', 0));

        // Unknown domains are ignored rather than leaking other data.
        $this->metrics(['domain' => 'evil.test'])->assertInertia(fn (Assert $page) => $page
            ->where('stats.sent', 3)->where('filters.domain', null));
    }

    public function test_opens_and_clicks_are_not_tracked_until_events_exist(): void
    {
        $this->message(['status' => 'delivered']);

        $this->metrics()->assertInertia(fn (Assert $page) => $page
            ->where('tracking.opens', false)
            ->where('tracking.clicks', false)
            ->where('stats.open_rate', null)
            ->where('stats.click_rate', null)
            ->where('stats.opened', 0));

        $this->message(['status' => 'delivered', 'meta' => ['first_opened_at' => now()->toIso8601String(), 'open_count' => 3]]);
        $this->message(['status' => 'delivered', 'meta' => ['first_opened_at' => now()->toIso8601String(), 'first_clicked_at' => now()->toIso8601String()]]);
        $this->message(['status' => 'delivered']);

        $this->metrics()->assertInertia(fn (Assert $page) => $page
            ->where('tracking.opens', true)
            ->where('tracking.clicks', true)
            ->where('stats.opened', 2)
            ->where('stats.clicked', 1)
            ->where('stats.open_rate', 50)
            ->where('stats.click_rate', 25));
    }

    public function test_per_broadcast_numbers(): void
    {
        $broadcast = Broadcast::factory()->create([
            'organization_id' => $this->org->id,
            'status' => 'sent',
            'from' => 'news@acme.test',
            'recipient_count' => 3,
            'sent_at' => now(),
        ]);

        $delivered = $this->message(['status' => 'delivered', 'meta' => ['first_opened_at' => now()->toIso8601String(), 'first_clicked_at' => now()->toIso8601String()]]);
        $bounced = $this->message(['status' => 'bounced']);
        BroadcastRecipient::query()->create(['broadcast_id' => $broadcast->id, 'message_id' => $delivered->id, 'email' => 'a@x.test', 'status' => 'sent']);
        BroadcastRecipient::query()->create(['broadcast_id' => $broadcast->id, 'message_id' => $bounced->id, 'email' => 'b@x.test', 'status' => 'sent']);
        BroadcastRecipient::query()->create(['broadcast_id' => $broadcast->id, 'email' => 'c@x.test', 'status' => 'failed']);

        $this->metrics()->assertInertia(fn (Assert $page) => $page
            ->has('broadcasts', 1)
            ->where('broadcasts.0.id', $broadcast->id)
            ->where('broadcasts.0.recipients', 3)
            ->where('broadcasts.0.sent', 2)
            ->where('broadcasts.0.failed', 1)
            ->where('broadcasts.0.delivered', 1)
            ->where('broadcasts.0.bounced', 1)
            ->where('broadcasts.0.opened', 1)
            ->where('broadcasts.0.clicked', 1)
            ->where('broadcasts.0.delivery_rate', 50));

        $this->metrics(['domain' => 'other.test'])->assertInertia(fn (Assert $page) => $page->has('broadcasts', 0));
    }

    public function test_metrics_are_scoped_to_the_current_workspace(): void
    {
        $this->message(['status' => 'delivered']);
        Message::factory()->create(['organization_id' => Organization::factory()->create()->id, 'direction' => 'outbound', 'status' => 'delivered']);

        $this->metrics()->assertInertia(fn (Assert $page) => $page->where('stats.delivered', 1));
    }
}
