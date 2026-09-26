<?php

namespace Tests\Feature;

use App\Jobs\DispatchWebhook;
use App\Models\Domain;
use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookHostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_send_dispatches_webhook_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);
        $org = Organization::factory()->create([
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);
        Domain::factory()->verified()->create([
            'organization_id' => $org->id,
            'name' => 'acme.test',
        ]);
        Webhook::factory()->create([
            'organization_id' => $org->id,
            'events' => ['email.sent'],
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('emails.store'), [
                'from' => 'hello@acme.test',
                'to' => 'customer@example.com',
                'subject' => 'Hook me',
                'html' => '<p>Hi</p>',
            ])
            ->assertRedirect();

        Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job) use ($org) {
            return $job->organizationId === $org->id && $job->event === 'email.sent';
        });
    }

    public function test_dispatch_webhook_creates_delivery_record(): void
    {
        Http::fake([
            'https://hooks.example.com/*' => Http::response('ok', 200),
        ]);
        $this->app->instance(WebhookHostResolver::class, new class extends WebhookHostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });

        $org = Organization::factory()->create();
        $webhook = Webhook::factory()->create([
            'organization_id' => $org->id,
            'url' => 'https://hooks.example.com/mail',
            'events' => ['email.sent'],
            'is_active' => true,
        ]);

        (new DispatchWebhook($org->id, 'email.sent', ['id' => 'abc']))->handle();

        $this->assertDatabaseHas('webhook_deliveries', [
            'webhook_id' => $webhook->id,
            'event' => 'email.sent',
            'status' => 'success',
            'response_status' => 200,
        ]);
        $this->assertSame(1, WebhookDelivery::query()->count());
        Http::assertSentCount(1);
    }
}
