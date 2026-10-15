<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Domain;
use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Mobile API routes must enforce the same billing lock as the web.
 *
 * When a workspace's trial expires or payment is past_due, write operations
 * (send, reply, create contacts, archive, spam, trash) should return 402.
 * Read operations are allowed.
 */
class MobileBillingLockTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->user = User::factory()->create();
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);
        $this->org = Organization::factory()->create([
            'status' => 'past_due',
            'plan' => 'Trial',
            'mail_provider_id' => $provider->id,
        ]);
        $this->org->users()->attach($this->user->id, ['role' => 'owner']);

        Subscription::factory()->create([
            'organization_id' => $this->org->id,
            'status' => 'past_due',
            'plan_name' => 'Trial',
            'current_period_ends_at' => now()->subDay(),
        ]);

        $this->token = $this->user->createToken('Test')->plainTextToken;
    }

    private function api()
    {
        return $this->withToken($this->token)
            ->withHeaders(['X-Workspace-Id' => $this->org->id]);
    }

    public function test_expired_trial_allows_inbox_read(): void
    {
        Thread::factory()->create(['organization_id' => $this->org->id]);

        $response = $this->api()->getJson('/api/v1/mobile/inbox');

        $response->assertOk()
            ->assertJsonStructure(['threads', 'pagination', 'folder']);
    }

    public function test_expired_trial_allows_thread_view(): void
    {
        $thread = Thread::factory()->create(['organization_id' => $this->org->id]);

        $response = $this->api()->getJson("/api/v1/mobile/inbox/{$thread->id}");

        $response->assertOk()
            ->assertJsonStructure(['thread']);
    }

    public function test_expired_trial_allows_contacts_read(): void
    {
        Contact::factory()->create(['organization_id' => $this->org->id]);

        $response = $this->api()->getJson('/api/v1/mobile/contacts');

        $response->assertOk()
            ->assertJsonStructure(['contacts']);
    }

    public function test_expired_trial_blocks_send_with_402(): void
    {
        Domain::factory()->verified()->create([
            'organization_id' => $this->org->id,
            'name' => 'acme.test',
        ]);

        $response = $this->api()->postJson('/api/v1/mobile/emails', [
            'from' => 'hello@acme.test',
            'to' => 'customer@example.com',
            'subject' => 'Test',
            'html' => '<p>Hello</p>',
        ]);

        $response->assertStatus(402)
            ->assertJson([
                'payment_required' => true,
            ]);
    }

    public function test_expired_trial_blocks_contact_create_with_402(): void
    {
        $response = $this->api()->postJson('/api/v1/mobile/contacts', [
            'email' => 'new@example.com',
            'first_name' => 'Test',
        ]);

        $response->assertStatus(402)
            ->assertJson([
                'payment_required' => true,
            ]);
    }

    public function test_expired_trial_blocks_archive_with_402(): void
    {
        $thread = Thread::factory()->create([
            'organization_id' => $this->org->id,
            'is_archived' => false,
        ]);

        $response = $this->api()->postJson("/api/v1/mobile/inbox/{$thread->id}/archive");

        $response->assertStatus(402)
            ->assertJson([
                'payment_required' => true,
            ]);
    }

    public function test_expired_trial_blocks_spam_with_402(): void
    {
        $thread = Thread::factory()->create([
            'organization_id' => $this->org->id,
            'is_spam' => false,
        ]);

        $response = $this->api()->postJson("/api/v1/mobile/inbox/{$thread->id}/spam");

        $response->assertStatus(402)
            ->assertJson([
                'payment_required' => true,
            ]);
    }

    public function test_expired_trial_blocks_trash_with_402(): void
    {
        $thread = Thread::factory()->create([
            'organization_id' => $this->org->id,
            'is_trashed' => false,
        ]);

        $response = $this->api()->postJson("/api/v1/mobile/inbox/{$thread->id}/trash");

        $response->assertStatus(402)
            ->assertJson([
                'payment_required' => true,
            ]);
    }

    public function test_active_workspace_allows_all_operations(): void
    {
        $activeOrg = Organization::factory()->create([
            'status' => 'active',
            'plan' => 'Pro',
            'mail_provider_id' => $this->org->mailProvider->id,
        ]);
        $activeOrg->users()->attach($this->user->id, ['role' => 'owner']);

        Subscription::factory()->create([
            'organization_id' => $activeOrg->id,
            'status' => 'active',
            'plan_name' => 'Pro',
            'current_period_ends_at' => now()->addMonth(),
        ]);

        $thread = Thread::factory()->create([
            'organization_id' => $activeOrg->id,
            'is_archived' => false,
        ]);

        $response = $this->withToken($this->token)
            ->withHeaders(['X-Workspace-Id' => $activeOrg->id])
            ->postJson("/api/v1/mobile/inbox/{$thread->id}/archive");

        $response->assertOk()
            ->assertJsonPath('is_archived', true);
    }

    public function test_active_trial_allows_all_operations(): void
    {
        $trialOrg = Organization::factory()->create([
            'status' => 'trial',
            'plan' => 'Trial',
            'mail_provider_id' => $this->org->mailProvider->id,
        ]);
        $trialOrg->users()->attach($this->user->id, ['role' => 'owner']);

        Subscription::factory()->create([
            'organization_id' => $trialOrg->id,
            'status' => 'trial',
            'plan_name' => 'Trial',
            'current_period_ends_at' => now()->addDays(7),
        ]);

        $thread = Thread::factory()->create([
            'organization_id' => $trialOrg->id,
            'is_archived' => false,
        ]);

        $response = $this->withToken($this->token)
            ->withHeaders(['X-Workspace-Id' => $trialOrg->id])
            ->postJson("/api/v1/mobile/inbox/{$thread->id}/archive");

        $response->assertOk()
            ->assertJsonPath('is_archived', true);
    }

    public function test_billing_lock_message_describes_plan_requirement(): void
    {
        $response = $this->api()->postJson('/api/v1/mobile/contacts', [
            'email' => 'new@example.com',
            'first_name' => 'Test',
        ]);

        $response->assertStatus(402);
        $message = $response->json('message');
        $this->assertNotEmpty($message);
        $this->assertStringContainsStringIgnoringCase('trial', $message);
    }
}
