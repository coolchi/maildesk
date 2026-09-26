<?php

namespace Tests\Feature;

use App\Mail\Contracts\MailProvider as MailProviderContract;
use App\Mail\DTO\OutboundEmail;
use App\Mail\DTO\ProviderSendResult;
use App\Mail\MailManager;
use App\Models\ApiKey;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Domain;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\BroadcastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SendFailureLoggingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Log::spy();

        $this->user = User::factory()->create();
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);
        $this->org = Organization::factory()->create(['mail_provider_id' => $provider->id, 'default_provider' => 'resend']);
        $this->org->users()->attach($this->user->id, ['role' => 'owner']);
        Domain::factory()->verified()->create(['organization_id' => $this->org->id, 'name' => 'acme.test']);

        $failing = new class implements MailProviderContract
        {
            public function name(): string
            {
                return 'resend';
            }

            public function send(OutboundEmail $email): ProviderSendResult
            {
                return new ProviderSendResult(success: false, error: 'API rejected request (api_key=re_supersecret123456)');
            }
        };

        $manager = Mockery::mock(MailManager::class)->makePartial();
        $manager->shouldReceive('forOrganization')->andReturn($failing);
        $this->app->instance(MailManager::class, $manager);
    }

    private function assertLoggedFailure(?int $broadcastId = null): void
    {
        $message = Message::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $message->status);

        Log::shouldHaveReceived('warning')->once()->withArgs(function ($text, $context) use ($message, $broadcastId) {
            return $text === 'Email send failed'
                && $context['message_id'] === $message->uuid
                && $context['provider'] === 'resend'
                && $context['broadcast_id'] === $broadcastId
                && str_contains($context['error'], 'API rejected request')
                && ! str_contains(json_encode($context), 're_supersecret123456');
        });
    }

    public function test_compose_send_failure_is_logged_without_credentials(): void
    {
        $this->actingAs($this->user)->withSession(['current_organization_id' => $this->org->id])
            ->post(route('emails.store'), [
                'from' => 'hello@acme.test',
                'to' => 'customer@example.com',
                'subject' => 'Hi',
                'html' => '<p>Hi</p>',
            ]);

        $this->assertLoggedFailure();
    }

    public function test_api_send_failure_is_logged(): void
    {
        $plain = ApiKey::issue($this->org, 'Test')['plain'];

        $this->withHeaders(['Authorization' => 'Bearer '.$plain])
            ->postJson('/api/v1/emails', [
                'from' => 'hello@acme.test',
                'to' => 'customer@example.com',
                'subject' => 'Hi',
                'html' => '<p>Hi</p>',
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        $this->assertLoggedFailure();
    }

    public function test_broadcast_send_failure_is_logged(): void
    {
        $broadcast = Broadcast::factory()->create([
            'organization_id' => $this->org->id,
            'from' => 'news@acme.test',
            'status' => 'sending',
        ]);
        $recipient = BroadcastRecipient::query()->create([
            'broadcast_id' => $broadcast->id,
            'email' => 'reader@example.com',
            'status' => 'pending',
        ]);

        app(BroadcastService::class)->sendTo($recipient);

        $this->assertSame('failed', $recipient->fresh()->status);
        $this->assertLoggedFailure($broadcast->id);
    }

    public function test_logging_defaults_rotate_daily_and_tests_do_not_write_the_real_log(): void
    {
        // phpunit.xml sets LOG_CHANNEL=null, which config/logging.php maps to
        // the "null" channel (even when a test switches to production).
        $this->assertSame('null', config('logging.default'));
        $this->assertStringContainsString('<env name="LOG_CHANNEL" value="null"/>', file_get_contents(base_path('phpunit.xml')));

        $config = require base_path('config/logging.php');
        $this->assertSame('daily', $config['channels']['daily']['driver']);
        $this->assertSame(14, (int) $config['channels']['daily']['max_files']);
        // The stack defaults to daily when LOG_STACK is not set in .env.
        $this->assertStringContainsString("env('LOG_STACK', 'daily')", file_get_contents(base_path('config/logging.php')));
    }
}
