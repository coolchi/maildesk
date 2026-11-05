<?php

namespace Tests\Feature;

use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Contact;
use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\User;
use App\Services\BroadcastService;
use App\Services\SegmentMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AudienceSegmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Organization}
     */
    private function member(): array
    {
        $user = User::factory()->create();
        $provider = MailProvider::factory()->create([
            'status' => 'active',
            'driver' => 'resend',
        ]);
        $org = Organization::factory()->create([
            'default_provider' => 'resend',
            'mail_provider_id' => $provider->id,
            'status' => 'active',
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $org];
    }

    private function as(User $user, Organization $org): static
    {
        return $this->actingAs($user)->withSession(['current_organization_id' => $org->id]);
    }

    public function test_creates_segment_with_rules_and_lists_it_on_audience(): void
    {
        [$user, $org] = $this->member();
        Contact::factory()->create([
            'organization_id' => $org->id,
            'email' => 'ann@acme.test',
            'meta' => ['status' => 'subscribed'],
        ]);
        Contact::factory()->create([
            'organization_id' => $org->id,
            'email' => 'bob@other.test',
            'meta' => ['status' => 'subscribed'],
        ]);

        $this->as($user, $org)->post(route('audience.segments.store'), [
            'name' => 'Acme subscribers',
            'description' => 'People at acme',
            'rules' => [
                ['field' => 'meta.status', 'op' => 'eq', 'value' => 'subscribed'],
                ['field' => 'email_domain', 'op' => 'contains', 'value' => 'acme.test'],
            ],
        ])->assertRedirect();

        $segment = Segment::query()->firstOrFail();
        $this->assertSame('Acme subscribers', $segment->name);
        $this->assertCount(2, $segment->rules);
        $this->assertSame(1, app(SegmentMembership::class)->count($segment));

        $this->as($user, $org)->get(route('audience'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Audience/Index')
                ->has('segments', 1)
                ->where('segments.0.name', 'Acme subscribers')
                ->where('segments.0.count', 1)
                ->where('segments.0.contacts', 1));
    }

    public function test_attaches_and_detaches_contacts_on_a_segment(): void
    {
        [$user, $org] = $this->member();
        $ann = Contact::factory()->create(['organization_id' => $org->id, 'email' => 'ann@example.com']);
        $bob = Contact::factory()->create(['organization_id' => $org->id, 'email' => 'bob@example.com']);
        $segment = Segment::factory()->create(['organization_id' => $org->id, 'rules' => null]);

        $this->as($user, $org)->post(route('audience.segments.contacts.attach', $segment), [
            'contact_ids' => [$ann->id, $bob->id],
        ])->assertRedirect();

        $this->assertEqualsCanonicalizing([$ann->id, $bob->id], $segment->contacts()->pluck('contacts.id')->all());
        $this->assertSame(2, app(SegmentMembership::class)->count($segment));

        $this->as($user, $org)
            ->delete(route('audience.segments.contacts.detach', [$segment, $bob]))
            ->assertRedirect();

        $this->assertEqualsCanonicalizing([$ann->id], $segment->fresh()->contacts()->pluck('contacts.id')->all());
    }

    public function test_manual_membership_is_filtered_by_rules(): void
    {
        [$user, $org] = $this->member();
        $subscribed = Contact::factory()->create([
            'organization_id' => $org->id,
            'email' => 'in@acme.test',
            'meta' => ['status' => 'subscribed'],
        ]);
        $unsubscribed = Contact::factory()->create([
            'organization_id' => $org->id,
            'email' => 'out@acme.test',
            'unsubscribed_at' => now(),
            'meta' => ['status' => 'unsubscribed'],
        ]);
        $otherDomain = Contact::factory()->create([
            'organization_id' => $org->id,
            'email' => 'other@elsewhere.test',
            'meta' => ['status' => 'subscribed'],
        ]);

        $segment = Segment::factory()->create([
            'organization_id' => $org->id,
            'rules' => [
                ['field' => 'meta.status', 'op' => 'eq', 'value' => 'subscribed'],
                ['field' => 'email_domain', 'op' => 'contains', 'value' => 'acme.test'],
            ],
        ]);
        $segment->contacts()->attach([$subscribed->id, $unsubscribed->id, $otherDomain->id]);

        $members = app(SegmentMembership::class)->query($segment)->pluck('email')->all();
        $this->assertSame(['in@acme.test'], $members);
    }

    public function test_broadcast_uses_segment_membership_including_rules(): void
    {
        [$user, $org] = $this->member();
        Contact::factory()->create([
            'organization_id' => $org->id,
            'email' => 'keep@acme.test',
            'meta' => ['status' => 'subscribed'],
        ]);
        Contact::factory()->create([
            'organization_id' => $org->id,
            'email' => 'skip@other.test',
            'meta' => ['status' => 'subscribed'],
        ]);

        $segment = Segment::factory()->create([
            'organization_id' => $org->id,
            'rules' => [
                ['field' => 'email_domain', 'op' => 'contains', 'value' => 'acme.test'],
            ],
        ]);

        $broadcast = Broadcast::query()->create([
            'organization_id' => $org->id,
            'name' => 'Acme only',
            'subject' => 'Hello',
            'html' => '<p>Hi</p>',
            'audience' => (string) $segment->id,
            'status' => 'queued',
        ]);

        app(BroadcastService::class)->buildRecipients($broadcast);

        $this->assertSame(['keep@acme.test'], BroadcastRecipient::query()->pluck('email')->all());
    }

    public function test_patch_contact_toggles_subscription_status(): void
    {
        [$user, $org] = $this->member();
        $contact = Contact::factory()->create([
            'organization_id' => $org->id,
            'email' => 'ann@example.com',
            'meta' => ['status' => 'subscribed'],
        ]);

        $this->as($user, $org)->patch(route('audience.update', $contact), [
            'status' => 'unsubscribed',
        ])->assertRedirect();

        $contact->refresh();
        $this->assertNotNull($contact->unsubscribed_at);
        $this->assertSame('unsubscribed', $contact->meta['status']);
        $this->assertFalse($contact->isSubscribed());

        $this->as($user, $org)->patch(route('audience.update', $contact), [
            'status' => 'subscribed',
        ])->assertRedirect();

        $contact->refresh();
        $this->assertNull($contact->unsubscribed_at);
        $this->assertSame('subscribed', $contact->meta['status']);
        $this->assertTrue($contact->isSubscribed());
    }

    public function test_foreign_segment_actions_return_not_found(): void
    {
        [$user, $org] = $this->member();
        $other = Organization::factory()->create();
        $foreign = Segment::factory()->create(['organization_id' => $other->id]);
        $contact = Contact::factory()->create(['organization_id' => $org->id]);

        $this->as($user, $org)
            ->put(route('audience.segments.update', $foreign), ['name' => 'Nope'])
            ->assertNotFound();

        $this->as($user, $org)
            ->delete(route('audience.segments.destroy', $foreign))
            ->assertNotFound();

        $this->as($user, $org)
            ->post(route('audience.segments.contacts.attach', $foreign), ['contact_ids' => [$contact->id]])
            ->assertNotFound();
    }

    public function test_destroy_segment_removes_it(): void
    {
        [$user, $org] = $this->member();
        $segment = Segment::factory()->create(['organization_id' => $org->id, 'name' => 'Temp']);

        $this->as($user, $org)
            ->delete(route('audience.segments.destroy', $segment))
            ->assertRedirect();

        $this->assertDatabaseMissing('segments', ['id' => $segment->id]);
    }

    public function test_can_edit_segment_name_description_and_rules(): void
    {
        [$user, $org] = $this->member();
        $segment = Segment::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Original Name',
            'description' => 'Original Description',
            'rules' => [
                ['field' => 'meta.status', 'op' => 'eq', 'value' => 'subscribed'],
            ],
        ]);

        $this->as($user, $org)
            ->put(route('audience.segments.update', $segment), [
                'name' => 'Updated Name',
                'description' => 'Updated Description',
                'rules' => [
                    ['field' => 'meta.status', 'op' => 'eq', 'value' => 'unsubscribed'],
                    ['field' => 'email_domain', 'op' => 'contains', 'value' => 'example.com'],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $segment->refresh();
        $this->assertSame('Updated Name', $segment->name);
        $this->assertSame('Updated Description', $segment->description);
        $this->assertCount(2, $segment->rules);
        $this->assertSame('unsubscribed', $segment->rules[0]['value']);
        $this->assertSame('example.com', $segment->rules[1]['value']);
    }

    public function test_segment_rules_are_exposed_in_workspace_array(): void
    {
        $org = Organization::factory()->create();
        $segment = Segment::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Test Segment',
            'rules' => [
                ['field' => 'meta.status', 'op' => 'eq', 'value' => 'subscribed'],
                ['field' => 'email_domain', 'op' => 'contains', 'value' => 'acme.com'],
            ],
        ]);

        $array = $segment->toWorkspaceArray();

        $this->assertArrayHasKey('rules', $array);
        $this->assertCount(2, $array['rules']);
        $this->assertSame('meta.status', $array['rules'][0]['field']);
    }
}
