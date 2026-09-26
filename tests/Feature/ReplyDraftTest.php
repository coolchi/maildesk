<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReplyDraftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['maildesk.ai.fake' => true]);
    }

    /** @return array{0: User, 1: Organization, 2: Thread} */
    private function threadWithInbound(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'support@acme.test',
        ]);
        $thread = Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mailbox->id,
            'subject' => 'Order delay',
        ]);
        Message::factory()->inbound()->create([
            'organization_id' => $org->id,
            'thread_id' => $thread->id,
            'mailbox_id' => $mailbox->id,
            'from_email' => 'customer@example.com',
            'to' => ['support@acme.test'],
            'subject' => 'Order delay',
            'text_body' => 'My order has not arrived yet. Can you help?',
        ]);

        return [$user, $org, $thread];
    }

    private function enableReplyDraft(): void
    {
        app(PlatformSettings::class)->set([
            'ai_enabled' => true,
            'ai_reply_draft' => true,
            'ai_provider' => 'openai',
        ]);
    }

    public function test_suggest_reply_returns_html_when_enabled(): void
    {
        $this->enableReplyDraft();
        [$user, $org, $thread] = $this->threadWithInbound();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->postJson(route('inbox.suggest-reply', $thread->id))
            ->assertOk()
            ->assertJsonPath('html', '<p>Thanks for reaching out. We are looking into this and will follow up shortly.</p>');
    }

    public function test_suggest_reply_is_forbidden_when_feature_disabled(): void
    {
        [$user, $org, $thread] = $this->threadWithInbound();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->postJson(route('inbox.suggest-reply', $thread->id))
            ->assertForbidden()
            ->assertJsonPath('message', 'Reply draft is not enabled.');
    }

    public function test_suggest_reply_is_404_for_other_tenants_threads(): void
    {
        $this->enableReplyDraft();
        [$user, $org] = $this->threadWithInbound();
        $other = Thread::factory()->create();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->postJson(route('inbox.suggest-reply', $other->id))
            ->assertNotFound();
    }

    public function test_inbox_page_exposes_reply_draft_enabled_flag(): void
    {
        $this->enableReplyDraft();
        [$user, $org] = $this->threadWithInbound();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('inbox'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inbox/Index')
                ->where('replyDraftEnabled', true));
    }

    public function test_invalid_tone_is_rejected(): void
    {
        $this->enableReplyDraft();
        [$user, $org, $thread] = $this->threadWithInbound();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->postJson(route('inbox.suggest-reply', $thread->id), ['tone' => 'sarcastic'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tone']);
    }
}
