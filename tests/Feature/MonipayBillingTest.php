<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\OrganizationHost;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MonipayBillingTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLIC_KEY = 'pub_test_FakePublicKey123';

    private const SECRET_KEY = 'pri_test_FakeSecretKey_MustNeverLeak_987';

    private const CHECKOUT = 'https://checkout.monipay.ng/pay/txn_ref_001';

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.monipay.public_key' => self::PUBLIC_KEY,
            'services.monipay.secret_key' => self::SECRET_KEY,
            'services.monipay.webhook_secret' => null,
        ]);

        // plans.price is whole units ($20 in the UI); the NGN price is explicit.
        $this->plan = Plan::factory()->create([
            'key' => 'tx_pro',
            'name' => 'Pro',
            'price' => 20,
            'price_kobo' => 500000, // ₦5,000.00
            'interval' => 'month',
        ]);
    }

    private function workspace(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $provider = MailProvider::factory()->create(['key' => 'resend_'.uniqid(), 'driver' => 'resend', 'type' => 'api', 'status' => 'active']);
        $org = Organization::factory()->create([
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
            'plan' => 'Free',
        ]);
        $org->users()->attach($user->id, ['role' => $role]);

        return [$user, $org];
    }

    private function as(User $user, Organization $org): static
    {
        return $this->actingAs($user)->withSession(['current_organization_id' => $org->id]);
    }

    private function fakeInitialize(): void
    {
        Http::fake([
            'api.monipay.ng/transaction/initialize' => Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => self::CHECKOUT,
                    'access_code' => 'ac_001xyz',
                    'reference' => 'txn_ref_001',
                ],
            ]),
        ]);
    }

    private function pendingPayment(Organization $org, User $user, array $overrides = []): Payment
    {
        return Payment::query()->create(array_merge([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'plan_id' => $this->plan->id,
            'plan_key' => $this->plan->key,
            'provider' => 'monipay',
            'reference' => 'md_'.strtolower((string) str()->ulid()),
            'amount' => 500000,
            'currency' => 'NGN',
            'status' => 'pending',
        ], $overrides));
    }

    private function verifyResponse(Payment $payment, array $data = [], int $http = 200): array
    {
        return [
            'api.monipay.ng/transaction/verify/*' => Http::response([
                'status' => true,
                'message' => 'Verification successful',
                'data' => array_merge([
                    'id' => 'ACX692A42E4B9218',
                    'reference' => $payment->reference,
                    'amount' => 500000,
                    'status' => 'success',
                    'gateway_response' => 'Successful',
                    'paid_at' => '2026-09-26 01:49:57',
                    'channel' => 'bank_transfer',
                    'currency' => 'NGN',
                    'customer' => ['email' => 'x@example.com'],
                    'authorization' => ['bin' => '408408', 'last4' => '4081', 'authorization_code' => 'AUTH_secret'],
                    'fees' => 0,
                ], $data),
            ], $http),
        ];
    }

    private function webhook(array $payload, ?string $secret = self::SECRET_KEY, ?string $signature = null)
    {
        $body = json_encode($payload);
        $signature ??= hash_hmac('sha512', $body, (string) $secret);

        return $this->call('POST', '/api/v1/payments/monipay/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_MONIPAY_SIGNATURE' => $signature,
        ], $body);
    }

    public function test_initialize_sends_kobo_amount_and_redirects_to_hosted_checkout(): void
    {
        [$user, $org] = $this->workspace();
        $this->fakeInitialize();

        $this->as($user, $org)
            ->post(route('billing.monipay.initialize'), ['plan' => 'tx_pro'])
            ->assertRedirect(self::CHECKOUT);

        $payment = Payment::query()->sole();
        $this->assertSame('pending', $payment->status);
        $this->assertSame(500000, $payment->amount);
        $this->assertSame('ac_001xyz', $payment->access_code);
        $this->assertSame('txn_ref_001', $payment->provider_reference);
        $this->assertStringStartsWith('md_', $payment->reference);

        Http::assertSent(function (HttpRequest $request) use ($payment, $user) {
            return $request->url() === 'https://api.monipay.ng/transaction/initialize'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer '.self::PUBLIC_KEY)
                && str_contains($request->header('Content-Type')[0] ?? '', 'application/json')
                && $request['amount'] === 500000
                && $request['currency'] === 'NGN'
                && $request['email'] === $user->email
                && $request['reference'] === $payment->reference
                && $request['callback_url'] === url('/billing/monipay/callback')
                && $request['webhook_url'] === url('/api/v1/payments/monipay/webhook')
                && $request['metadata']['payment_id'] === $payment->id
                && $request['metadata']['plan'] === 'tx_pro';
        });
    }

    public function test_initialize_uses_tenant_subdomain_for_callback_and_supports_json(): void
    {
        config([
            'maildesk.base_domain' => 'maildesk.test',
            'maildesk.central_domains' => ['maildesk.test'],
            'app.url' => 'http://maildesk.test',
        ]);
        [$user, $org] = $this->workspace('admin');
        OrganizationHost::factory()->create([
            'organization_id' => $org->id,
            'subdomain' => 'acme',
            'host' => 'acme.maildesk.test',
            'status' => 'active',
        ]);
        $this->fakeInitialize();

        $this->actingAs($user)
            ->postJson('http://acme.maildesk.test/billing/monipay/initialize', ['plan' => 'tx_pro'])
            ->assertOk()
            ->assertJson([
                'authorization_url' => self::CHECKOUT,
                'access_code' => 'ac_001xyz',
                'reference' => Payment::query()->sole()->reference,
            ]);

        Http::assertSent(fn (HttpRequest $request) => $request['callback_url'] === 'http://acme.maildesk.test/billing/monipay/callback');
    }

    public function test_minimum_amount_and_free_plans_are_enforced(): void
    {
        [$user, $org] = $this->workspace();
        Http::fake();
        Plan::factory()->create(['key' => 'tiny', 'price' => 1, 'price_kobo' => 4999]);
        Plan::factory()->create(['key' => 'tx_free', 'price' => 0, 'price_kobo' => null]);

        $this->as($user, $org)->post(route('billing.monipay.initialize'), ['plan' => 'tiny'])
            ->assertSessionHasErrors('plan');
        $this->as($user, $org)->post(route('billing.monipay.initialize'), ['plan' => 'tx_free'])
            ->assertSessionHasErrors('plan');

        Http::assertNothingSent();
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_plan_price_without_explicit_kobo_is_derived_from_whole_units(): void
    {
        [$user, $org] = $this->workspace();
        $this->fakeInitialize();
        Plan::factory()->create(['key' => 'mkt_pro', 'price' => 49, 'price_kobo' => null]);
        config(['services.monipay.naira_per_price_unit' => 1500]);

        $this->as($user, $org)->post(route('billing.monipay.initialize'), ['plan' => 'mkt_pro'])
            ->assertRedirect(self::CHECKOUT);

        $this->assertSame(49 * 1500 * 100, Payment::query()->sole()->amount);
    }

    public function test_members_cannot_initialize_payments(): void
    {
        [$user, $org] = $this->workspace('member');
        Http::fake();

        $this->as($user, $org)->post(route('billing.monipay.initialize'), ['plan' => 'tx_pro'])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_unconfigured_keys_block_initialize_and_settings_shows_not_configured(): void
    {
        config(['services.monipay.public_key' => null, 'services.monipay.secret_key' => null]);
        [$user, $org] = $this->workspace();
        Http::fake();

        $this->as($user, $org)->post(route('billing.monipay.initialize'), ['plan' => 'tx_pro'])
            ->assertSessionHasErrors('plan');

        $this->as($user, $org)->get(route('settings', 'billing'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('payments.configured', false));

        Http::assertNothingSent();
    }

    public function test_callback_verifies_with_private_key_and_fulfils_once(): void
    {
        [$user, $org] = $this->workspace();
        $payment = $this->pendingPayment($org, $user);
        Http::fake($this->verifyResponse($payment));

        $this->as($user, $org)
            ->get(route('billing.monipay.callback', ['reference' => $payment->reference]))
            ->assertRedirect(route('settings', 'billing'))
            ->assertSessionHas('success');

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.monipay.ng/transaction/verify/'.$payment->reference
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer '.self::SECRET_KEY));

        $payment->refresh();
        $this->assertSame('paid', $payment->status);
        $this->assertNotNull($payment->fulfilled_at);
        $this->assertSame('bank_transfer', $payment->channel);
        $this->assertStringNotContainsString('AUTH_secret', json_encode($payment->verify_payload));
        $this->assertStringNotContainsString('4081', json_encode($payment->verify_payload));

        $subscription = Subscription::query()->where('organization_id', $org->id)->sole();
        $this->assertSame($this->plan->id, $subscription->plan_id);
        $this->assertSame('active', $subscription->status);
        $this->assertTrue($subscription->current_period_ends_at->between(now()->addMonth()->subMinute(), now()->addMonth()->addMinute()));
        $this->assertSame('Pro', $org->fresh()->plan);
        $periodEnd = $subscription->current_period_ends_at;

        // Repeat visit: no second verify-driven fulfilment, period not extended again.
        $this->as($user, $org)
            ->get(route('billing.monipay.callback', ['trxref' => $payment->reference]))
            ->assertSessionHas('success');

        Http::assertSentCount(1);
        $this->assertSame(1, Subscription::query()->where('organization_id', $org->id)->count());
        $this->assertTrue($periodEnd->equalTo($subscription->fresh()->current_period_ends_at));
    }

    public function test_callback_falls_back_to_reference_in_session(): void
    {
        [$user, $org] = $this->workspace();
        $payment = $this->pendingPayment($org, $user);
        Http::fake($this->verifyResponse($payment, ['status' => 'APPROVED']));

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id, 'monipay_pending_reference' => $payment->reference])
            ->get(route('billing.monipay.callback'))
            ->assertSessionHas('success');

        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_paying_again_for_same_plan_extends_the_period(): void
    {
        [$user, $org] = $this->workspace();
        $end = now()->addDays(10)->startOfSecond();
        Subscription::factory()->create([
            'organization_id' => $org->id,
            'plan_id' => $this->plan->id,
            'product' => 'transactional',
            'status' => 'active',
            'current_period_ends_at' => $end,
        ]);
        $payment = $this->pendingPayment($org, $user);
        Http::fake($this->verifyResponse($payment));

        $this->as($user, $org)->get(route('billing.monipay.callback', ['reference' => $payment->reference]));

        $sub = Subscription::query()->where('organization_id', $org->id)->sole();
        $this->assertTrue($sub->current_period_ends_at->equalTo($end->copy()->addMonthNoOverflow()));
    }

    public function test_amount_mismatch_is_not_fulfilled(): void
    {
        [$user, $org] = $this->workspace();
        $payment = $this->pendingPayment($org, $user);
        Http::fake($this->verifyResponse($payment, ['amount' => 5000]));

        $this->as($user, $org)
            ->get(route('billing.monipay.callback', ['reference' => $payment->reference]))
            ->assertSessionHas('error');

        $payment->refresh();
        $this->assertNull($payment->fulfilled_at);
        $this->assertNotSame('paid', $payment->status);
        $this->assertTrue($payment->meta['review_required']);
        $this->assertSame(0, Subscription::query()->where('organization_id', $org->id)->count());
    }

    public function test_currency_mismatch_is_not_fulfilled(): void
    {
        [$user, $org] = $this->workspace();
        $payment = $this->pendingPayment($org, $user);
        Http::fake($this->verifyResponse($payment, ['currency' => 'USD']));

        $this->as($user, $org)->get(route('billing.monipay.callback', ['reference' => $payment->reference]));

        $this->assertNull($payment->fresh()->fulfilled_at);
    }

    public function test_failed_status_marks_payment_failed(): void
    {
        [$user, $org] = $this->workspace();
        $payment = $this->pendingPayment($org, $user);
        Http::fake($this->verifyResponse($payment, ['status' => 'failed']));

        $this->as($user, $org)
            ->get(route('billing.monipay.callback', ['reference' => $payment->reference]))
            ->assertSessionHas('error');

        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->fulfilled_at);
    }

    public function test_verify_error_shapes_do_not_fulfil(): void
    {
        [$user, $org] = $this->workspace();
        $payment = $this->pendingPayment($org, $user);
        Http::fake([
            'api.monipay.ng/transaction/verify/*' => Http::sequence()
                ->push(['success' => false, 'message' => 'Merchant Not Found'], 404)
                ->push(['status' => false, 'message' => 'Transaction not found'], 200),
        ]);

        $this->as($user, $org)->get(route('billing.monipay.callback', ['reference' => $payment->reference]))->assertSessionHas('error');
        $this->as($user, $org)->get(route('billing.monipay.callback', ['reference' => $payment->reference]))->assertSessionHas('error');

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->fulfilled_at);
    }

    public function test_webhook_charge_success_verifies_and_fulfils_idempotently(): void
    {
        [$user, $org] = $this->workspace();
        $payment = $this->pendingPayment($org, $user);
        Http::fake($this->verifyResponse($payment));

        $payload = ['event' => 'charge.success', 'data' => [
            'id' => 802, 'reference' => $payment->reference, 'amount' => 500000, 'currency' => 'NGN', 'status' => 'success',
        ]];

        $this->webhook($payload)->assertOk()->assertJson(['status' => 'paid']);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/transaction/verify/'.$payment->reference));
        $this->assertSame('paid', $payment->fresh()->status);

        // Replayed webhook and a later callback are both no-ops.
        $this->webhook($payload)->assertOk()->assertJson(['status' => 'already_paid']);
        $this->as($user, $org)->get(route('billing.monipay.callback', ['reference' => $payment->reference]))->assertSessionHas('success');

        Http::assertSentCount(1);
        $this->assertSame(1, Subscription::query()->where('organization_id', $org->id)->count());
    }

    public function test_webhook_after_callback_is_idempotent_and_accepts_prefixed_signature(): void
    {
        [$user, $org] = $this->workspace();
        $payment = $this->pendingPayment($org, $user);
        Http::fake($this->verifyResponse($payment));

        $this->as($user, $org)->get(route('billing.monipay.callback', ['reference' => $payment->reference]));

        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $payment->reference]]);
        $this->webhook(json_decode($body, true), signature: 'sha512='.hash_hmac('sha512', $body, self::SECRET_KEY))
            ->assertOk()
            ->assertJson(['status' => 'already_paid']);

        Http::assertSentCount(1);
    }

    public function test_webhook_uses_dedicated_secret_when_set(): void
    {
        config(['services.monipay.webhook_secret' => 'whsec_dedicated_test']);
        Http::fake();

        $this->webhook(['event' => 'charge.success', 'data' => ['reference' => 'nope']])->assertStatus(401);
        $this->webhook(['event' => 'charge.success', 'data' => ['reference' => 'nope']], 'whsec_dedicated_test')
            ->assertOk()->assertJson(['status' => 'ignored']);
    }

    public function test_webhook_with_invalid_signature_is_rejected(): void
    {
        [$user, $org] = $this->workspace();
        $payment = $this->pendingPayment($org, $user);
        Http::fake();

        $this->webhook(['event' => 'charge.success', 'data' => ['reference' => $payment->reference]], 'wrong-secret')
            ->assertStatus(401);
        $this->webhook(['event' => 'charge.success', 'data' => ['reference' => $payment->reference]], signature: '')
            ->assertStatus(401);

        Http::assertNothingSent();
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_unknown_webhook_event_is_ignored(): void
    {
        Http::fake();

        $this->webhook(['event' => 'charge.failed', 'data' => ['reference' => 'md_x']])
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        Http::assertNothingSent();
    }

    public function test_webhook_without_secret_in_production_returns_503(): void
    {
        config(['services.monipay.secret_key' => null, 'services.monipay.webhook_secret' => null]);
        $this->app['env'] = 'production';

        $this->webhook(['event' => 'charge.success', 'data' => ['reference' => 'md_x']], 'anything')
            ->assertStatus(503);
    }

    public function test_settings_lists_payments_and_never_leaks_the_secret_key(): void
    {
        [$user, $org] = $this->workspace();
        $this->pendingPayment($org, $user, ['reference' => 'md_visible_ref', 'status' => 'paid']);

        $this->as($user, $org)->get(route('settings', 'billing'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.configured', true)
                ->where('payments.can_manage', true)
                ->where('payments.payments.0.reference', 'md_visible_ref')
                ->where('payments.payments.0.amount_formatted', '₦5,000.00')
                ->where('payments.plans.0.key', 'tx_pro'));

        foreach (['settings/billing', 'settings/usage', 'emails'] as $path) {
            $html = $this->as($user, $org)->get('/'.$path)->assertOk()->getContent();
            $this->assertStringNotContainsString(self::SECRET_KEY, $html, $path);

            $json = $this->as($user, $org)
                ->withHeaders([
                    'X-Inertia' => 'true',
                    'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                    'X-Requested-With' => 'XMLHttpRequest',
                ])
                ->get('/'.$path)
                ->assertOk()
                ->getContent();
            $this->assertStringNotContainsString(self::SECRET_KEY, $json, $path);
            $this->flushHeaders();
        }
    }
}
