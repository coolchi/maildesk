<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MobileInboxTest extends TestCase
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
        $this->org = Organization::factory()->create(['mail_provider_id' => $provider->id]);
        $this->org->users()->attach($this->user->id, ['role' => 'owner']);

        $this->token = $this->user->createToken('Test')->plainTextToken;
    }

    private function api()
    {
        return $this->withToken($this->token)
            ->withHeaders(['X-Workspace-Id' => $this->org->id]);
    }

    public function test_inbox_requires_workspace_id(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/v1/mobile/inbox')
            ->assertStatus(400);
    }

    public function test_inbox_lists_threads(): void
    {
        $thread = Thread::factory()->create([
            'organization_id' => $this->org->id,
            'is_read' => false,
        ]);

        Message::factory()->inbound()->create([
            'organization_id' => $this->org->id,
            'thread_id' => $thread->id,
        ]);

        $response = $this->api()->getJson('/api/v1/mobile/inbox');

        $response->assertOk()
            ->assertJsonStructure([
                'threads' => [['id', 'subject', 'snippet', 'unread', 'messages']],
                'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
                'folder',
                'unread_count',
            ]);

        $this->assertCount(1, $response->json('threads'));
    }

    public function test_inbox_filters_by_folder(): void
    {
        Thread::factory()->create([
            'organization_id' => $this->org->id,
            'is_archived' => false,
            'is_spam' => false,
            'is_trashed' => false,
        ]);

        Thread::factory()->create([
            'organization_id' => $this->org->id,
            'is_archived' => true,
        ]);

        Thread::factory()->create([
            'organization_id' => $this->org->id,
            'is_spam' => true,
        ]);

        Thread::factory()->create([
            'organization_id' => $this->org->id,
            'is_trashed' => true,
        ]);

        $this->api()->getJson('/api/v1/mobile/inbox?folder=inbox')
            ->assertOk()
            ->assertJsonCount(1, 'threads');

        $this->api()->getJson('/api/v1/mobile/inbox?folder=archive')
            ->assertOk()
            ->assertJsonCount(1, 'threads');

        $this->api()->getJson('/api/v1/mobile/inbox?folder=spam')
            ->assertOk()
            ->assertJsonCount(1, 'threads');

        $this->api()->getJson('/api/v1/mobile/inbox?folder=trash')
            ->assertOk()
            ->assertJsonCount(1, 'threads');
    }

    public function test_inbox_pagination(): void
    {
        Thread::factory()->count(25)->create([
            'organization_id' => $this->org->id,
        ]);

        $response = $this->api()->getJson('/api/v1/mobile/inbox?per_page=10');

        $response->assertOk();
        $this->assertCount(10, $response->json('threads'));
        $this->assertSame(1, $response->json('pagination.current_page'));
        $this->assertSame(3, $response->json('pagination.last_page'));

        $page2 = $this->api()->getJson('/api/v1/mobile/inbox?per_page=10&page=2');
        $page2->assertOk();
        $this->assertCount(10, $page2->json('threads'));
    }

    public function test_show_thread_marks_as_read(): void
    {
        $thread = Thread::factory()->create([
            'organization_id' => $this->org->id,
            'is_read' => false,
        ]);

        Message::factory()->inbound()->create([
            'organization_id' => $this->org->id,
            'thread_id' => $thread->id,
        ]);

        $response = $this->api()->getJson("/api/v1/mobile/inbox/{$thread->id}");

        $response->assertOk()
            ->assertJsonStructure(['thread' => ['id', 'subject', 'messages']]);

        $this->assertTrue($thread->fresh()->is_read);
    }

    public function test_mark_read_unread(): void
    {
        $thread = Thread::factory()->create([
            'organization_id' => $this->org->id,
            'is_read' => false,
        ]);

        $this->api()->patchJson("/api/v1/mobile/inbox/{$thread->id}/read", ['read' => true])
            ->assertOk()
            ->assertJsonPath('is_read', true);

        $this->assertTrue($thread->fresh()->is_read);

        $this->api()->patchJson("/api/v1/mobile/inbox/{$thread->id}/read", ['read' => false])
            ->assertOk()
            ->assertJsonPath('is_read', false);

        $this->assertFalse($thread->fresh()->is_read);
    }

    public function test_toggle_archive(): void
    {
        $thread = Thread::factory()->create([
            'organization_id' => $this->org->id,
            'is_archived' => false,
        ]);

        $this->api()->postJson("/api/v1/mobile/inbox/{$thread->id}/archive")
            ->assertOk()
            ->assertJsonPath('is_archived', true);

        $this->assertTrue($thread->fresh()->is_archived);

        $this->api()->postJson("/api/v1/mobile/inbox/{$thread->id}/archive")
            ->assertOk()
            ->assertJsonPath('is_archived', false);

        $this->assertFalse($thread->fresh()->is_archived);
    }

    public function test_toggle_spam(): void
    {
        $thread = Thread::factory()->create([
            'organization_id' => $this->org->id,
            'is_spam' => false,
        ]);

        $this->api()->postJson("/api/v1/mobile/inbox/{$thread->id}/spam")
            ->assertOk()
            ->assertJsonPath('is_spam', true);

        $this->assertTrue($thread->fresh()->is_spam);
    }

    public function test_toggle_trash(): void
    {
        $thread = Thread::factory()->create([
            'organization_id' => $this->org->id,
            'is_trashed' => false,
        ]);

        $this->api()->postJson("/api/v1/mobile/inbox/{$thread->id}/trash")
            ->assertOk()
            ->assertJsonPath('is_trashed', true);

        $this->assertTrue($thread->fresh()->is_trashed);
        $this->assertNotNull($thread->fresh()->trashed_at);
    }

    public function test_cannot_access_other_workspace_threads(): void
    {
        $otherOrg = Organization::factory()->create();
        $thread = Thread::factory()->create([
            'organization_id' => $otherOrg->id,
        ]);

        $this->api()->getJson("/api/mobile/inbox/{$thread->id}")
            ->assertStatus(404);
    }

    public function test_mailbox_scoped_user_only_sees_own_threads(): void
    {
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        $otherMailbox = Mailbox::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $this->org->users()->updateExistingPivot($this->user->id, ['role' => 'member']);

        $ownThread = Thread::factory()->create([
            'organization_id' => $this->org->id,
            'mailbox_id' => $mailbox->id,
        ]);

        $otherThread = Thread::factory()->create([
            'organization_id' => $this->org->id,
            'mailbox_id' => $otherMailbox->id,
        ]);

        $response = $this->api()->getJson('/api/v1/mobile/inbox');

        $response->assertOk();
        $threadIds = collect($response->json('threads'))->pluck('id')->all();

        $this->assertContains($ownThread->id, $threadIds);
        $this->assertNotContains($otherThread->id, $threadIds);
    }
}
