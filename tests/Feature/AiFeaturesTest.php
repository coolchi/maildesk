<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['maildesk.ai.fake' => true]);
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function member(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $org];
    }

    private function enable(string ...$features): void
    {
        $payload = [
            'ai_enabled' => true,
            'ai_provider' => 'openai',
        ];

        foreach ($features as $feature) {
            $payload['ai_'.$feature] = true;
        }

        app(PlatformSettings::class)->set($payload);
    }

    private function as(User $user, Organization $org): static
    {
        return $this->actingAs($user)->withSession(['current_organization_id' => $org->id]);
    }

    public function test_thread_summary_stores_summary_on_thread(): void
    {
        $this->enable('thread_summary');
        [$user, $org] = $this->member();
        $mailbox = Mailbox::factory()->create(['organization_id' => $org->id]);
        $thread = Thread::factory()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mailbox->id,
            'subject' => 'Order delay',
        ]);
        Message::factory()->inbound()->create([
            'organization_id' => $org->id,
            'thread_id' => $thread->id,
            'mailbox_id' => $mailbox->id,
            'text_body' => 'My order is late.',
        ]);

        $this->as($user, $org)
            ->postJson(route('inbox.summarize', $thread->id))
            ->assertOk()
            ->assertJsonPath('summary', 'Customer needs help with a delayed order.');

        $thread->refresh();
        $this->assertSame('Customer needs help with a delayed order.', $thread->ai['summary']);
        $this->assertNotEmpty($thread->ai['action_items']);
    }

    public function test_compose_assist_rewrites_html(): void
    {
        $this->enable('compose_assist');
        [$user, $org] = $this->member();

        $this->as($user, $org)
            ->postJson(route('ai.compose'), [
                'action' => 'rewrite',
                'html' => '<p>Hello there this is a draft</p>',
            ])
            ->assertOk()
            ->assertJsonPath('html', '<p>Here is a clearer version of your message.</p>');
    }

    public function test_compose_assist_suggests_subjects(): void
    {
        $this->enable('compose_assist');
        [$user, $org] = $this->member();

        $this->as($user, $org)
            ->postJson(route('ai.compose'), [
                'action' => 'subject',
                'html' => '<p>Product launch details</p>',
                'subject' => 'Hi',
            ])
            ->assertOk()
            ->assertJsonPath('subjects.0', 'Quick update for you');
    }

    public function test_broadcast_assist_returns_subjects_and_html(): void
    {
        $this->enable('broadcast_assist');
        [$user, $org] = $this->member();

        $this->as($user, $org)
            ->postJson(route('ai.broadcast'), [
                'brief' => 'Announce the spring sale',
            ])
            ->assertOk()
            ->assertJsonStructure(['subjects', 'html']);
    }

    public function test_automation_smart_steps_returns_workflow(): void
    {
        $this->enable('automation_smart_steps');
        [$user, $org] = $this->member();

        $this->as($user, $org)
            ->postJson(route('ai.automation'), [
                'prompt' => 'When a contact is added wait then send welcome email',
            ])
            ->assertOk()
            ->assertJsonPath('trigger', 'contact.added')
            ->assertJsonPath('steps.0.type', 'trigger');
    }

    public function test_nl_segment_returns_rules(): void
    {
        $this->enable('nl_segments');
        [$user, $org] = $this->member();

        $this->as($user, $org)
            ->postJson(route('ai.segment'), [
                'prompt' => 'Subscribed contacts at acme.com',
            ])
            ->assertOk()
            ->assertJsonPath('rules.0.field', 'meta.status')
            ->assertJsonPath('rules.1.field', 'email_domain');
    }

    public function test_bounce_explanation_returns_guidance(): void
    {
        $this->enable('bounce_explanations');
        [$user, $org] = $this->member();
        $message = Message::factory()->create([
            'organization_id' => $org->id,
            'direction' => 'outbound',
            'status' => 'bounced',
            'subject' => 'Hello',
            'to' => ['gone@example.com'],
            'meta' => ['bounce' => ['type' => 'hard', 'reason' => 'mailbox not found']],
        ]);

        $this->as($user, $org)
            ->postJson(route('ai.bounce', $message->uuid))
            ->assertOk()
            ->assertJsonStructure(['explanation', 'causes', 'fixes']);
    }

    public function test_in_app_help_answers_question(): void
    {
        $this->enable('in_app_help');
        [$user, $org] = $this->member();

        $this->as($user, $org)
            ->postJson(route('ai.help'), [
                'question' => 'How do I verify a domain?',
            ])
            ->assertOk()
            ->assertJsonStructure(['answer']);
    }

    public function test_disabled_feature_is_forbidden(): void
    {
        [$user, $org] = $this->member();

        $this->as($user, $org)
            ->postJson(route('ai.compose'), [
                'action' => 'rewrite',
                'html' => '<p>Hi</p>',
            ])
            ->assertForbidden();
    }
}
