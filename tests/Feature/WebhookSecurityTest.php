<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhook;
use App\Jobs\DispatchWebhook;
use App\Mail\Events\DeliveryEvent;
use App\Models\ApiKey;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\DeliveryEventService;
use App\Services\Webhooks\WebhookDeliverer;
use App\Services\Webhooks\WebhookHostResolver;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, list<string>> $map */
    private function fakeDns(array $map): void
    {
        $this->app->instance(WebhookHostResolver::class, new class($map) extends WebhookHostResolver
        {
            public function __construct(private array $map) {}

            public function resolve(string $host): array
            {
                return $this->map[$host] ?? [];
            }
        });
    }

    private function member(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => $role]);

        return [$user, $org];
    }

    private function webhook(Organization $org, array $attributes = []): Webhook
    {
        return Webhook::factory()->create(array_merge([
            'organization_id' => $org->id,
            'url' => 'https://hooks.example.com/mail',
            'events' => ['email.sent'],
            'is_active' => true,
        ], $attributes));
    }

    private function delivery(Webhook $webhook): WebhookDelivery
    {
        return WebhookDelivery::query()->create([
            'webhook_id' => $webhook->id,
            'event' => 'email.sent',
            'payload' => ['event' => 'email.sent', 'data' => ['id' => 'abc'], 'sent_at' => now()->toIso8601String()],
            'status' => 'pending',
            'attempts' => 0,
        ]);
    }

    public static function blockedUrls(): array
    {
        return [
            'loopback' => ['https://127.0.0.1/hook'],
            'localhost' => ['https://localhost/hook'],
            'localhost subdomain' => ['https://api.localhost/hook'],
            'private 10/8' => ['https://10.0.0.5/hook'],
            'private 172.16/12' => ['https://172.16.4.2/hook'],
            'private 192.168/16' => ['https://192.168.1.10/hook'],
            'metadata' => ['https://169.254.169.254/latest/meta-data'],
            'cgnat' => ['https://100.64.1.1/hook'],
            'unspecified v4' => ['https://0.0.0.0/hook'],
            'unspecified v6' => ['https://[::]/hook'],
            'loopback v6' => ['https://[::1]/hook'],
            'ula v6' => ['https://[fd00::1]/hook'],
            'link-local v6' => ['https://[fe80::1]/hook'],
            'mapped v6' => ['https://[::ffff:127.0.0.1]/hook'],
            '.local' => ['https://printer.local/hook'],
            '.internal' => ['https://metadata.google.internal/hook'],
            'decimal ip' => ['https://2130706433/hook'],
            'short ip' => ['https://127.1/hook'],
            'single label' => ['https://intranet/hook'],
            'credentials' => ['https://user:pass@hooks.example.com/hook'],
            'ftp' => ['ftp://hooks.example.com/hook'],
        ];
    }

    #[DataProvider('blockedUrls')]
    public function test_store_rejects_internal_urls(string $url): void
    {
        [$user, $org] = $this->member();

        $this->actingAs($user)->withSession(['current_organization_id' => $org->id])
            ->post(route('webhooks.store'), ['url' => $url, 'events' => ['email.sent']])
            ->assertSessionHasErrors('url');

        $this->assertSame(0, Webhook::query()->count());
    }

    public function test_update_rejects_internal_urls(): void
    {
        [$user, $org] = $this->member();
        $webhook = $this->webhook($org);

        $this->actingAs($user)->withSession(['current_organization_id' => $org->id])
            ->put(route('webhooks.update', $webhook), ['url' => 'https://169.254.169.254/'])
            ->assertSessionHasErrors('url');

        $this->assertSame('https://hooks.example.com/mail', $webhook->fresh()->url);
    }

    public function test_http_is_rejected_outside_local_and_testing(): void
    {
        [$user, $org] = $this->member();

        $this->app['env'] = 'production';
        // CSRF is only skipped automatically in the testing environment.
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->actingAs($user)->withSession(['current_organization_id' => $org->id])
            ->post(route('webhooks.store'), ['url' => 'http://hooks.example.com/mail', 'events' => ['email.sent']])
            ->assertSessionHasErrors('url');

        $this->actingAs($user)->withSession(['current_organization_id' => $org->id])
            ->post(route('webhooks.store'), ['url' => 'https://hooks.example.com/mail', 'events' => ['email.sent']])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Webhook::query()->count());
    }

    public function test_delivery_blocks_hostname_resolving_to_private_ip(): void
    {
        Http::fake();
        Log::spy();
        $this->fakeDns(['hooks.example.com' => ['93.184.216.34', '10.0.0.7']]);

        $org = Organization::factory()->create();
        $delivery = $this->delivery($this->webhook($org));

        (new DeliverWebhook($delivery->id))->handle(app(WebhookDeliverer::class));

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertStringContainsString('Blocked', $delivery->response_body);
        Http::assertNothingSent();
        Log::shouldHaveReceived('error')->once();
    }

    public function test_delivery_blocks_stored_internal_url_even_if_validation_was_bypassed(): void
    {
        Http::fake();
        $this->fakeDns([]);

        $org = Organization::factory()->create();
        $delivery = $this->delivery($this->webhook($org, ['url' => 'http://127.0.0.1:8080/hook']));

        (new DeliverWebhook($delivery->id))->handle(app(WebhookDeliverer::class));

        $this->assertSame('failed', $delivery->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_redirects_are_not_followed(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);
        $this->fakeDns(['hooks.example.com' => ['93.184.216.34']]);

        $org = Organization::factory()->create();
        $delivery = $this->delivery($this->webhook($org));

        (new DeliverWebhook($delivery->id))->handle(app(WebhookDeliverer::class));

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(302, $delivery->response_status);
        Http::assertSentCount(1);
    }

    public function test_request_is_signed_with_legacy_and_timestamped_signatures(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->fakeDns(['hooks.example.com' => ['93.184.216.34']]);

        $org = Organization::factory()->create();
        $webhook = $this->webhook($org);
        $delivery = $this->delivery($webhook);

        (new DeliverWebhook($delivery->id))->handle(app(WebhookDeliverer::class));

        $this->assertSame('success', $delivery->fresh()->status);
        Http::assertSent(function (HttpRequest $request) use ($webhook) {
            $body = $request->body();
            $ts = $request->header('X-MailDesk-Timestamp')[0] ?? '';

            return ctype_digit($ts)
                && abs((int) $ts - time()) < 60
                && $request->header('X-MailDesk-Signature')[0] === hash_hmac('sha256', $body, $webhook->secret)
                && $request->header('X-MailDesk-Signature-V2')[0] === hash_hmac('sha256', $ts.'.'.$body, $webhook->secret)
                && $request->header('X-MailDesk-Event')[0] === 'email.sent'
                && json_decode($body, true)['data']['id'] === 'abc';
        });
    }

    public function test_dispatch_queues_one_delivery_job_per_subscribed_endpoint(): void
    {
        Queue::fake();
        $org = Organization::factory()->create();
        $this->webhook($org);
        $this->webhook($org, ['url' => 'https://other.example.com/x']);
        $this->webhook($org, ['events' => ['email.bounced']]);
        $this->webhook($org, ['is_active' => false]);

        (new DispatchWebhook($org->id, 'email.sent', ['id' => 'abc']))->handle();

        Queue::assertPushed(DeliverWebhook::class, 2);
        $this->assertSame(2, WebhookDelivery::query()->where('status', 'pending')->where('attempts', 0)->count());
    }

    public function test_failed_deliveries_are_retried_with_backoff_and_logged(): void
    {
        Http::fake(['*' => Http::response('boom', 503)]);
        Log::spy();
        $this->fakeDns(['hooks.example.com' => ['93.184.216.34']]);

        $org = Organization::factory()->create();
        $webhook = $this->webhook($org);
        $delivery = $this->delivery($webhook);

        for ($i = 0; $i < DeliverWebhook::MAX_ATTEMPTS; $i++) {
            (new DeliverWebhook($delivery->id))->handle(app(WebhookDeliverer::class));
        }

        $delivery->refresh();
        $this->assertSame(DeliverWebhook::MAX_ATTEMPTS, $delivery->attempts);
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(503, $delivery->response_status);
        Http::assertSentCount(DeliverWebhook::MAX_ATTEMPTS);

        Log::shouldHaveReceived('warning')->times(DeliverWebhook::MAX_ATTEMPTS)
            ->withArgs(fn ($message, $context) => $context['webhook_id'] === $webhook->id
                && $context['event'] === 'email.sent'
                && $context['status'] === 503
                && ! array_key_exists('secret', $context)
                && ! array_key_exists('payload', $context)
                && ! str_contains(json_encode($context), $webhook->secret));
        Log::shouldHaveReceived('error')->once()
            ->withArgs(fn ($message, $context) => $message === 'Webhook delivery failed permanently'
                && $context['attempts'] === DeliverWebhook::MAX_ATTEMPTS);
    }

    public function test_queued_retry_is_released_with_increasing_delay(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);
        $this->fakeDns(['hooks.example.com' => ['93.184.216.34']]);

        $org = Organization::factory()->create();
        $delivery = $this->delivery($this->webhook($org));

        $job = new DeliverWebhook($delivery->id);
        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(DeliverWebhook::BACKOFF[0]);
        $job->setJob($queueJob);

        $job->handle(app(WebhookDeliverer::class));

        $this->assertSame(1, $delivery->fresh()->attempts);
        $this->assertSame([30, 120, 600, 1800], DeliverWebhook::BACKOFF);
    }

    public function test_retry_succeeds_after_a_failure(): void
    {
        Http::fake(['*' => Http::sequence()->push('down', 500)->push('ok', 200)]);
        $this->fakeDns(['hooks.example.com' => ['93.184.216.34']]);

        $org = Organization::factory()->create();
        $delivery = $this->delivery($this->webhook($org));

        (new DeliverWebhook($delivery->id))->handle(app(WebhookDeliverer::class));
        (new DeliverWebhook($delivery->id))->handle(app(WebhookDeliverer::class));

        $delivery->refresh();
        $this->assertSame('success', $delivery->status);
        $this->assertSame(2, $delivery->attempts);
    }

    public function test_client_errors_are_not_retried(): void
    {
        Http::fake(['*' => Http::response('nope', 410)]);
        Log::spy();
        $this->fakeDns(['hooks.example.com' => ['93.184.216.34']]);

        $org = Organization::factory()->create();
        $delivery = $this->delivery($this->webhook($org));

        $job = new DeliverWebhook($delivery->id);
        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldNotReceive('release');
        $job->setJob($queueJob);
        $job->handle(app(WebhookDeliverer::class));

        $this->assertSame(1, $delivery->fresh()->attempts);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_test_ping_sends_signed_event_and_reports_result(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->fakeDns(['hooks.example.com' => ['93.184.216.34']]);
        [$user, $org] = $this->member();
        $webhook = $this->webhook($org);

        $this->actingAs($user)->withSession(['current_organization_id' => $org->id])
            ->from(route('webhooks.show', $webhook))
            ->post(route('webhooks.test', $webhook))
            ->assertRedirect(route('webhooks.show', $webhook))
            ->assertSessionHas('success', 'Test event delivered (HTTP 200).');

        $this->assertDatabaseHas('webhook_deliveries', [
            'webhook_id' => $webhook->id,
            'event' => 'webhook.test',
            'status' => 'success',
            'attempts' => 1,
        ]);
        Http::assertSent(fn (HttpRequest $r) => $r->header('X-MailDesk-Event')[0] === 'webhook.test'
            && $r->header('X-MailDesk-Signature')[0] === hash_hmac('sha256', $r->body(), $webhook->secret));
    }

    public function test_test_ping_reports_failures_and_blocks_internal_targets(): void
    {
        Http::fake();
        $this->fakeDns(['hooks.example.com' => ['192.168.0.10']]);
        [$user, $org] = $this->member();
        $webhook = $this->webhook($org);

        $this->actingAs($user)->withSession(['current_organization_id' => $org->id])
            ->post(route('webhooks.test', $webhook))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Blocked'));

        Http::assertNothingSent();
    }

    public function test_test_ping_is_scoped_to_workspace(): void
    {
        [$user, $org] = $this->member();
        $foreign = $this->webhook(Organization::factory()->create());

        $this->actingAs($user)->withSession(['current_organization_id' => $org->id])
            ->post(route('webhooks.test', $foreign))
            ->assertNotFound();
    }

    public function test_owner_can_rotate_secret_and_it_is_shown_once(): void
    {
        [$user, $org] = $this->member('owner');
        $webhook = $this->webhook($org);
        $old = $webhook->secret;

        $this->actingAs($user)->withSession(['current_organization_id' => $org->id])
            ->post(route('webhooks.rotate', $webhook))
            ->assertRedirect(route('webhooks.show', $webhook))
            ->assertSessionHas('plain_webhook_secret', fn ($s) => str_starts_with($s, 'whsec_') && $s !== $old);

        $this->assertNotSame($old, $webhook->fresh()->secret);
    }

    public function test_members_cannot_rotate_secret(): void
    {
        [$user, $org] = $this->member('member');
        $webhook = $this->webhook($org);
        $old = $webhook->secret;

        $this->actingAs($user)->withSession(['current_organization_id' => $org->id])
            ->post(route('webhooks.rotate', $webhook))
            ->assertForbidden();

        $this->assertSame($old, $webhook->fresh()->secret);
    }

    public function test_show_page_exposes_can_manage_flag(): void
    {
        [$user, $org] = $this->member('owner');
        $webhook = $this->webhook($org);

        $this->actingAs($user)->withSession(['current_organization_id' => $org->id])
            ->get(route('webhooks.show', $webhook))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Webhooks/Show')->where('canManage', true));
    }

    public function test_clicked_events_are_tracked_and_dispatch_email_clicked_webhook(): void
    {
        Queue::fake();
        $org = Organization::factory()->create();
        $message = Message::factory()->create([
            'organization_id' => $org->id,
            'direction' => 'outbound',
            'status' => 'delivered',
            'provider' => 'resend',
            'provider_message_id' => 're_click_1',
            'meta' => null,
        ]);

        app(DeliveryEventService::class)->handle(new DeliveryEvent(
            type: DeliveryEvent::CLICKED,
            provider: 'resend',
            providerMessageId: 're_click_1',
            eventId: 'evt_1',
            details: ['link' => 'https://acme.test/pricing'],
        ));

        $message->refresh();
        $this->assertSame(1, $message->meta['click_count']);
        $this->assertSame('delivered', $message->status);
        Queue::assertPushed(DispatchWebhook::class, fn (DispatchWebhook $job) => $job->event === 'email.clicked');
    }

    public function test_onboarding_counts_only_enabled_webhooks_and_unexpired_keys(): void
    {
        [$user, $org] = $this->member();
        $this->webhook($org, ['is_active' => false]);
        ApiKey::factory()->create(['organization_id' => $org->id, 'expires_at' => now()->subDay()]);

        $this->actingAs($user)->withSession(['current_organization_id' => $org->id])->get('/domains')
            ->assertInertia(fn (Assert $page) => $page
                ->where('onboarding.webhook', false)
                ->where('onboarding.apiKey', false));

        $this->webhook($org);
        ApiKey::factory()->create(['organization_id' => $org->id, 'expires_at' => now()->addDay()]);

        $this->actingAs($user)->withSession(['current_organization_id' => $org->id])->get('/domains')
            ->assertInertia(fn (Assert $page) => $page
                ->where('onboarding.webhook', true)
                ->where('onboarding.apiKey', true));
    }
}
