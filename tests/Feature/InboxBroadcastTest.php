<?php

namespace Tests\Feature;

use App\Events\InboxUpdated;
use App\Models\Mailbox;
use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InboxBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');
        config(['maildesk.inbound.generic_secret' => 'generic-secret']);
    }

    /**
     * @return array{0: User, 1: Organization, 2: Mailbox}
     */
    private function workspace(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'support@acme.test',
            'inbox' => true,
            'status' => 'active',
        ]);

        return [$user, $org, $mailbox];
    }

    /**
     * Channel callbacks are registered on the default driver at boot (null in
     * tests). Re-bind them onto Reverb before authorizing private channels.
     */
    private function useReverbBroadcaster(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options.host' => 'localhost',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);

        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');
    }

    public function test_inbound_email_dispatches_inbox_updated_for_the_workspace(): void
    {
        [, $org] = $this->workspace();
        Event::fake([InboxUpdated::class]);

        $this->withHeader('X-MailDesk-Inbound-Secret', 'generic-secret')
            ->postJson('/api/v1/inbound/generic', [
                'from' => 'Jane <jane@example.com>',
                'to' => ['support@acme.test'],
                'subject' => 'Live update',
                'text' => 'Hello',
                'message_id' => '<live@example.com>',
            ])
            ->assertCreated();

        Event::assertDispatched(InboxUpdated::class, function (InboxUpdated $event) use ($org) {
            $payload = $event->broadcastWith();

            return $event->organization->is($org)
                && $event->mailboxId !== null
                && $event->unread === 1
                && $event->workspace_unread === 1
                && $event->broadcastAs() === 'inbox.updated'
                && $event->broadcastOn()[0]->name === 'private-organizations.'.$org->id.'.inbox'
                && ($payload['mailbox_id'] ?? null) === $event->mailboxId
                && ($payload['unread'] ?? null) === 1
                && ($payload['workspace_unread'] ?? null) === 1;
        });
    }

    public function test_inbox_updated_payload_scopes_unread_to_mailbox_and_keeps_workspace_total(): void
    {
        [, $org, $mailbox] = $this->workspace();
        $otherMailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'billing@acme.test',
            'inbox' => true,
            'status' => 'active',
        ]);

        Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mailbox->id,
            'is_read' => false,
            'is_archived' => false,
        ]);
        Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $otherMailbox->id,
            'is_read' => false,
            'is_archived' => false,
        ]);
        Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mailbox->id,
            'is_read' => true,
            'is_archived' => false,
        ]);

        $event = new InboxUpdated($org, $mailbox->id);
        $payload = $event->broadcastWith();

        $this->assertSame($mailbox->id, $payload['mailbox_id']);
        $this->assertSame(1, $payload['unread']);
        $this->assertSame(2, $payload['workspace_unread']);
        $this->assertNotSame('0:0:0', $payload['cursor']);
    }

    public function test_duplicate_inbound_does_not_dispatch_inbox_updated_again(): void
    {
        $this->workspace();
        Event::fake([InboxUpdated::class]);

        $payload = [
            'from' => 'Jane <jane@example.com>',
            'to' => ['support@acme.test'],
            'subject' => 'Once',
            'text' => 'Hello',
            'message_id' => '<once@example.com>',
        ];

        $this->withHeader('X-MailDesk-Inbound-Secret', 'generic-secret')
            ->postJson('/api/v1/inbound/generic', $payload)
            ->assertCreated();
        $this->withHeader('X-MailDesk-Inbound-Secret', 'generic-secret')
            ->postJson('/api/v1/inbound/generic', $payload)
            ->assertOk();

        Event::assertDispatchedTimes(InboxUpdated::class, 1);
    }

    public function test_workspace_member_can_authorize_inbox_channel(): void
    {
        [$user, $org] = $this->workspace();
        $this->useReverbBroadcaster();

        $this->actingAs($user)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-organizations.'.$org->id.'.inbox',
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_outsider_cannot_authorize_another_workspace_inbox_channel(): void
    {
        [, $org] = $this->workspace();
        $outsider = User::factory()->create();
        $other = Organization::factory()->create();
        $other->users()->attach($outsider->id, ['role' => 'owner']);
        $this->useReverbBroadcaster();

        $this->actingAs($outsider)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-organizations.'.$org->id.'.inbox',
            ])
            ->assertForbidden();
    }

    public function test_shared_broadcasting_prop_is_enabled_when_reverb_is_configured(): void
    {
        [$user, $org] = $this->workspace();
        Thread::factory()->create(['organization_id' => $org->id, 'is_read' => false]);

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('inbox'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('broadcasting.enabled', true)
                ->where('broadcasting.driver', 'reverb')
                ->where('inbox_unread', 1));
    }
}
