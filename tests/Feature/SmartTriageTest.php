<?php

namespace Tests\Feature;

use App\Jobs\ClassifyInboundMessage;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Services\SmartTriageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SmartTriageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['maildesk.ai.fake' => true]);
    }

    private function enableSmartTriage(): void
    {
        app(PlatformSettings::class)->set([
            'ai_enabled' => true,
            'ai_smart_triage' => true,
            'ai_provider' => 'openai',
        ]);
    }

    public function test_inbound_dispatches_classify_job_when_smart_triage_is_enabled(): void
    {
        Queue::fake();
        config(['maildesk.inbound.generic_secret' => 'generic-secret']);
        $this->enableSmartTriage();

        Mailbox::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'email' => 'support@acme.test',
        ]);

        $this->withHeader('X-MailDesk-Inbound-Secret', 'generic-secret')
            ->postJson('/api/v1/inbound/generic', [
                'from' => 'Jane <jane@example.com>',
                'to' => ['support@acme.test'],
                'subject' => 'Urgent help needed ASAP',
                'text' => 'Please help ASAP with my account.',
                'message_id' => '<triage-1@example.com>',
            ])
            ->assertCreated();

        Queue::assertPushed(ClassifyInboundMessage::class);
    }

    public function test_inbound_skips_classify_job_when_smart_triage_is_disabled(): void
    {
        Queue::fake();
        config(['maildesk.inbound.generic_secret' => 'generic-secret']);

        Mailbox::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'email' => 'support@acme.test',
        ]);

        $this->withHeader('X-MailDesk-Inbound-Secret', 'generic-secret')
            ->postJson('/api/v1/inbound/generic', [
                'from' => 'Jane <jane@example.com>',
                'to' => ['support@acme.test'],
                'subject' => 'Hello',
                'text' => 'Just saying hi.',
                'message_id' => '<triage-off@example.com>',
            ])
            ->assertCreated();

        Queue::assertNotPushed(ClassifyInboundMessage::class);
    }

    public function test_classify_job_stores_priority_intent_and_language_on_thread(): void
    {
        $this->enableSmartTriage();

        $org = Organization::factory()->create();
        $thread = Thread::factory()->create(['organization_id' => $org->id]);
        $message = Message::factory()->create([
            'organization_id' => $org->id,
            'thread_id' => $thread->id,
            'direction' => 'inbound',
            'status' => 'received',
            'subject' => 'Urgent billing issue ASAP',
            'text_body' => 'My invoice payment failed ASAP please help.',
            'from_email' => 'customer@example.com',
        ]);

        (new ClassifyInboundMessage($message->id))->handle(app(SmartTriageService::class));

        $thread->refresh();

        $this->assertSame('urgent', $thread->ai['priority']);
        $this->assertSame('support', $thread->ai['intent']);
        $this->assertSame('en', $thread->ai['language']);
        $this->assertSame('fake', $thread->ai['provider']);
    }

    public function test_thread_workspace_array_includes_ai_payload(): void
    {
        $thread = Thread::factory()->create([
            'ai' => [
                'priority' => 'high',
                'intent' => 'sales',
                'language' => 'en',
            ],
        ]);

        $payload = $thread->toWorkspaceArray();

        $this->assertSame([
            'priority' => 'high',
            'intent' => 'sales',
            'language' => 'en',
        ], $payload['ai']);
    }

    public function test_admin_can_save_ai_provider_and_encrypted_api_key(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $features = collect(array_keys(PlatformSettings::AI_FEATURES))
            ->mapWithKeys(fn (string $key) => [$key => $key === 'smart_triage'])
            ->all();

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), [
                'signup_open' => true,
                'platform_name' => '',
                'support_email' => '',
                'default_workspace_provider_id' => null,
                'trial_days' => 14,
                'ai_enabled' => true,
                'ai_features' => $features,
                'ai_provider' => 'openai',
                'ai_model' => 'gpt-4.1-mini',
                'ai_api_key' => 'sk-test-secret-key',
            ])
            ->assertSessionHasNoErrors();

        $settings = app(PlatformSettings::class);

        $this->assertSame('openai', $settings->aiProvider());
        $this->assertSame('gpt-4.1-mini', $settings->aiModel());
        $this->assertSame('sk-test-secret-key', $settings->aiApiKey());
        $this->assertTrue($settings->aiFeatureEnabled('smart_triage'));
        $this->assertNotSame('sk-test-secret-key', $settings->get('ai_api_key'));
    }
}
