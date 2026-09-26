<?php

namespace Tests\Feature;

use App\Jobs\AdvanceAutomationRun;
use App\Jobs\ProcessAutomationEvent;
use App\Models\ApiKey;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Domain;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\AutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutomationsMvpTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Organization, 2: string}
     */
    private function workspace(): array
    {
        $user = User::factory()->create();
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'array']);
        $org = Organization::factory()->create([
            'mail_provider_id' => $provider->id,
            'default_provider' => 'array',
            'status' => 'active',
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);
        Domain::factory()->verified()->create([
            'organization_id' => $org->id,
            'name' => 'acme.test',
        ]);

        $issued = ApiKey::issue($org, 'Test', $user, ['*']);

        return [$user, $org, $issued['plain']];
    }

    private function asOwner(User $user, Organization $org): static
    {
        return $this->actingAs($user)->withSession(['current_organization_id' => $org->id]);
    }

    private function api(string $token): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * @return list<array{type: string, label: string}>
     */
    private function defaultSteps(): array
    {
        return [
            ['type' => 'trigger', 'label' => 'When user.created'],
            ['type' => 'delay', 'label' => 'Wait 1 hour'],
            ['type' => 'email', 'label' => 'Send welcome email'],
        ];
    }

    public function test_store_creates_automation_with_parsed_delay_and_email_config(): void
    {
        [$user, $org] = $this->workspace();

        $this->asOwner($user, $org)
            ->post(route('automations.store'), [
                'name' => 'Welcome flow',
                'status' => 'enabled',
                'trigger' => 'user.created',
                'steps' => $this->defaultSteps(),
            ])
            ->assertRedirect();

        $automation = Automation::query()->firstOrFail();
        $this->assertSame('active', $automation->status);
        $this->assertSame('user.created', $automation->trigger);
        $this->assertCount(3, $automation->steps);

        $delay = $automation->steps()->where('type', 'delay')->firstOrFail();
        $this->assertSame(60, data_get($delay->config, 'duration_minutes'));
        $this->assertSame('Wait 1 hour', data_get($delay->config, 'label'));

        $email = $automation->steps()->where('type', 'email')->firstOrFail();
        $this->assertSame('Send welcome email', data_get($email->config, 'subject'));
        $this->assertStringContainsString('Send welcome email', (string) data_get($email->config, 'html'));

        $this->assertSame(5, app(AutomationService::class)->parseDelayMinutes('Wait 5 minutes'));
        $this->assertSame(1440, app(AutomationService::class)->parseDelayMinutes('Wait 1 day'));
        $this->assertSame(2880, app(AutomationService::class)->parseDelayMinutes('Wait 2 days'));
    }

    public function test_event_runs_active_automation_through_delay_then_sends_email(): void
    {
        Queue::fake([ProcessAutomationEvent::class, AdvanceAutomationRun::class]);

        [$user, $org, $token] = $this->workspace();

        $this->asOwner($user, $org)->post(route('automations.store'), [
            'name' => 'Welcome flow',
            'status' => 'enabled',
            'trigger' => 'user.created',
            'steps' => $this->defaultSteps(),
        ])->assertRedirect();

        $automation = Automation::query()->firstOrFail();

        $this->api($token)
            ->postJson('/api/v1/events', [
                'name' => 'user.created',
                'payload' => ['email' => 'maya@studio.co'],
            ])
            ->assertAccepted()
            ->assertJsonPath('accepted', true);

        Queue::assertPushed(ProcessAutomationEvent::class, function (ProcessAutomationEvent $job) use ($org): bool {
            return $job->organizationId === $org->id
                && $job->eventName === 'user.created'
                && ($job->payload['email'] ?? null) === 'maya@studio.co';
        });

        (new ProcessAutomationEvent($org->id, 'user.created', ['email' => 'maya@studio.co']))
            ->handle(app(AutomationService::class));

        $run = AutomationRun::query()->firstOrFail();
        $this->assertSame($automation->id, $run->automation_id);
        $this->assertSame('maya@studio.co', $run->contact_email);

        Queue::assertPushed(AdvanceAutomationRun::class, fn (AdvanceAutomationRun $job) => $job->automationRunId === $run->id);

        (new AdvanceAutomationRun($run->id))->handle(app(AutomationService::class));

        $run->refresh();
        $this->assertSame('waiting', $run->status);
        $this->assertNotNull($run->due_at);
        $this->assertSame(0, Message::query()->count());

        $this->travel(61)->minutes();

        (new AdvanceAutomationRun($run->id))->handle(app(AutomationService::class));

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, Message::query()->count());

        $message = Message::query()->firstOrFail();
        $this->assertSame('hello@acme.test', $message->from_email);
        $this->assertSame('Send welcome email', $message->subject);
        $this->assertContains('maya@studio.co', $message->to);

        $automation->loadCount('runs');
        $this->assertSame(1, $automation->toWorkspaceArray()['runs']);
    }

    public function test_paused_automation_does_not_start_a_run(): void
    {
        Queue::fake([AdvanceAutomationRun::class]);

        [$user, $org, $token] = $this->workspace();

        $this->asOwner($user, $org)->post(route('automations.store'), [
            'name' => 'Paused flow',
            'status' => 'disabled',
            'trigger' => 'user.created',
            'steps' => $this->defaultSteps(),
        ])->assertRedirect();

        $this->assertSame('paused', Automation::query()->value('status'));

        $this->api($token)
            ->postJson('/api/v1/events', [
                'name' => 'user.created',
                'payload' => ['email' => 'maya@studio.co'],
            ])
            ->assertAccepted();

        $this->assertSame(0, AutomationRun::query()->count());
        $this->assertSame(0, Message::query()->count());
        Queue::assertNotPushed(AdvanceAutomationRun::class);
    }

    public function test_update_replaces_steps(): void
    {
        [$user, $org] = $this->workspace();

        $this->asOwner($user, $org)->post(route('automations.store'), [
            'name' => 'Flow',
            'status' => 'enabled',
            'trigger' => 'contact.added',
            'steps' => $this->defaultSteps(),
        ])->assertRedirect();

        $automation = Automation::query()->firstOrFail();

        $this->asOwner($user, $org)->put(route('automations.update', $automation), [
            'name' => 'Flow v2',
            'status' => 'enabled',
            'trigger' => 'email.opened',
            'steps' => [
                ['type' => 'trigger', 'label' => 'When email.opened'],
                ['type' => 'email', 'label' => 'Send follow-up'],
            ],
        ])->assertRedirect();

        $automation->refresh()->load('steps');
        $this->assertSame('Flow v2', $automation->name);
        $this->assertSame('email.opened', $automation->trigger);
        $this->assertCount(2, $automation->steps);
        $this->assertSame(['trigger', 'email'], $automation->steps->pluck('type')->all());
        $this->assertSame('Send follow-up', data_get($automation->steps[1]->config, 'subject'));
    }
}
