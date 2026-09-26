<?php

namespace App\Services\Billing;

use App\Models\Organization;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Plan upgrade payments through Monipay.
 *
 * A payment is only ever fulfilled after a server-side verify call (private
 * key) reports a success status for exactly the amount (kobo) and currency we
 * charged. Fulfilment runs in a DB transaction on a locked payment row and is
 * idempotent: the callback, the webhook and repeat visits can all race safely.
 */
class BillingService
{
    public function __construct(public MonipayClient $monipay) {}

    public function isConfigured(): bool
    {
        return $this->monipay->isConfigured();
    }

    public function currency(): string
    {
        return (string) config('services.monipay.currency', 'NGN');
    }

    public function minAmount(): int
    {
        return (int) config('services.monipay.min_amount', 5000);
    }

    /**
     * Plan price in kobo. plans.price is stored in whole currency units, so
     * unless the plan has an explicit price_kobo we multiply by the
     * configured naira rate (default 1) and by 100.
     */
    public function priceInKobo(Plan $plan): int
    {
        if ($plan->price_kobo !== null) {
            return (int) $plan->price_kobo;
        }

        $rate = (float) config('services.monipay.naira_per_price_unit', 1) ?: 1.0;

        return (int) round(((int) $plan->price) * $rate * 100);
    }

    public function isPaidPlan(Plan $plan): bool
    {
        return $this->priceInKobo($plan) > 0;
    }

    public function canManageBilling(?User $user, Organization $organization): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->isPlatformAdmin()) {
            return true;
        }

        $role = $organization->users()->whereKey($user->id)->first()?->pivot?->role;

        return in_array($role, ['owner', 'admin'], true);
    }

    /**
     * Props for the Settings → Billing tab. Never includes secret material.
     *
     * @return array<string, mixed>
     */
    public function settingsProps(?User $user, Organization $organization): array
    {
        $current = $organization->subscriptions()->where('status', 'active')->pluck('plan_id')->filter()->all();

        return [
            'configured' => $this->isConfigured(),
            'can_manage' => $this->canManageBilling($user, $organization),
            'currency' => $this->currency(),
            'min_amount' => $this->minAmount(),
            'plans' => Plan::query()->orderBy('product')->orderBy('price')->get()
                ->filter(fn (Plan $plan) => $this->isPaidPlan($plan))
                ->map(fn (Plan $plan) => [
                    'key' => $plan->key,
                    'name' => $plan->name,
                    'product' => $plan->product,
                    'interval' => $plan->interval,
                    'amount' => $this->priceInKobo($plan),
                    'amount_formatted' => $this->formatKobo($this->priceInKobo($plan)),
                    'payable' => $this->priceInKobo($plan) >= $this->minAmount(),
                    'current' => in_array($plan->id, $current, true),
                ])
                ->values()
                ->all(),
            'payments' => Payment::query()
                ->where('organization_id', $organization->id)
                ->with('plan')
                ->latest('id')
                ->limit(20)
                ->get()
                ->map->toBillingArray()
                ->values()
                ->all(),
        ];
    }

    public function formatKobo(int $kobo): string
    {
        return '₦'.number_format($kobo / 100, 2);
    }

    /**
     * Create a pending payment and initialize it with Monipay.
     *
     * @return array{payment: Payment, authorization_url: string, access_code: ?string}
     *
     * @throws BillingException
     */
    public function initialize(Organization $organization, ?User $user, Plan $plan, string $callbackUrl, ?string $webhookUrl = null): array
    {
        if (! $this->isConfigured()) {
            throw new BillingException('Payments are not configured.');
        }

        $amount = $this->priceInKobo($plan);

        if ($amount <= 0) {
            throw new BillingException('This plan is free and does not need a payment.');
        }

        if ($amount < $this->minAmount()) {
            throw new BillingException('The amount is below the minimum payable of '.$this->formatKobo($this->minAmount()).'.');
        }

        $payment = Payment::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user?->id,
            'plan_id' => $plan->id,
            'plan_key' => $plan->key,
            'provider' => 'monipay',
            'reference' => 'md_'.Str::lower((string) Str::ulid()),
            'amount' => $amount,
            'currency' => $this->currency(),
            'status' => Payment::STATUS_PENDING,
        ]);

        $email = $user?->email ?: $organization->owner_email;
        $name = trim((string) ($user?->name ?? ''));
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, null);

        $payload = array_filter([
            'email' => $email,
            'amount' => $amount,
            'reference' => $payment->reference,
            'callback_url' => $callbackUrl,
            'webhook_url' => $webhookUrl,
            'currency' => $this->currency(),
            'first_name' => $first ?: null,
            'last_name' => $last ?: null,
            'metadata' => [
                'payment_id' => $payment->id,
                'organization_id' => $organization->id,
                'user_id' => $user?->id,
                'plan' => $plan->key,
            ],
        ], fn ($value) => $value !== null);

        $context = $this->context($payment);

        try {
            $result = $this->monipay->initialize($payload);
        } catch (ConnectionException $e) {
            $this->markFailed($payment, 'initialize_connection_error');
            $this->log()->error('Monipay initialize failed: connection error', $context + ['error' => $e->getMessage()]);

            throw new BillingException('Could not reach the payment provider. Please try again.');
        }

        $body = $result['body'];
        $url = MonipayClient::field($body, ['authorization_url']);

        if (! $result['ok'] || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            $this->markFailed($payment, 'initialize_rejected');
            $this->log()->error('Monipay initialize rejected', $context + [
                'http_status' => $result['status'],
                'message' => MonipayClient::field($body, ['message']),
            ]);

            throw new BillingException('The payment provider could not start this payment. Please try again.');
        }

        $accessCode = MonipayClient::field($body, ['access_code']);

        $payment->update([
            'access_code' => is_scalar($accessCode) ? (string) $accessCode : null,
            'provider_reference' => $this->scalar(MonipayClient::field($body, ['order_id', 'reference'])),
            'trans_id' => $this->scalar(MonipayClient::field($body, ['trans_id'])),
        ]);

        $this->log()->info('Monipay payment initialized', $this->context($payment));

        return [
            'payment' => $payment,
            'authorization_url' => $url,
            'access_code' => $payment->access_code,
        ];
    }

    public function findByReference(?string $reference): ?Payment
    {
        $reference = trim((string) $reference);

        if ($reference === '') {
            return null;
        }

        return Payment::query()
            ->where('reference', $reference)
            ->orWhere('provider_reference', $reference)
            ->orWhere('trans_id', $reference)
            ->first();
    }

    /**
     * Verify with Monipay (private key) and fulfil when everything checks out.
     *
     * @return array{result: string, payment: Payment}
     *                                                 result: paid | already_paid | pending | failed | abandoned | mismatch | error
     */
    public function verifyAndFulfil(Payment $payment, string $source): array
    {
        if ($payment->isFulfilled()) {
            return ['result' => 'already_paid', 'payment' => $payment];
        }

        $context = $this->context($payment) + ['source' => $source];

        try {
            $result = $this->monipay->verify($payment->reference);
        } catch (ConnectionException $e) {
            $this->log()->error('Monipay verify failed: connection error', $context + ['error' => $e->getMessage()]);

            return ['result' => 'error', 'payment' => $payment];
        }

        if (! $result['ok']) {
            $this->log()->warning('Monipay verify returned HTTP error', $context + ['http_status' => $result['status']]);

            return ['result' => 'error', 'payment' => $payment];
        }

        return $this->applyVerification($payment, $result['body'], $source);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{result: string, payment: Payment}
     */
    protected function applyVerification(Payment $payment, array $body, string $source): array
    {
        return DB::transaction(function () use ($payment, $body, $source) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $context = $this->context($locked) + ['source' => $source];

            if ($locked->isFulfilled()) {
                return ['result' => 'already_paid', 'payment' => $locked];
            }

            $status = MonipayClient::transactionStatus($body);
            $safe = MonipayClient::safePayload($body);

            if (MonipayClient::isSuccessStatus($body)) {
                $amount = MonipayClient::amount($body);
                $currency = MonipayClient::field($body, ['currency']);

                if ($amount !== $locked->amount || ($currency !== null && strtoupper((string) $currency) !== $locked->currency)) {
                    $locked->update([
                        'verify_payload' => $safe,
                        'meta' => array_merge($locked->meta ?? [], [
                            'review_required' => true,
                            'failure_reason' => 'amount_or_currency_mismatch',
                            'reported_amount' => $amount,
                            'reported_currency' => $currency,
                        ]),
                    ]);
                    $this->log()->critical('Monipay payment NOT fulfilled: amount/currency mismatch', $context + [
                        'expected_amount' => $locked->amount,
                        'reported_amount' => $amount,
                        'reported_currency' => $currency,
                    ]);

                    return ['result' => 'mismatch', 'payment' => $locked];
                }

                $paidAt = MonipayClient::field($body, ['paid_at', 'paidAt']);

                $locked->fill([
                    'status' => Payment::STATUS_PAID,
                    'paid_at' => $this->parseDate($paidAt) ?? now(),
                    'channel' => $this->scalar(MonipayClient::field($body, ['channel'])),
                    'trans_id' => $locked->trans_id ?? $this->scalar(MonipayClient::field($body, ['trans_id'])),
                    'provider_reference' => $locked->provider_reference ?? $this->scalar(MonipayClient::field($body, ['order_id'])),
                    'verify_payload' => $safe,
                ]);

                $subscription = $this->fulfil($locked);

                $locked->subscription_id = $subscription->id;
                $locked->fulfilled_at = now();
                $locked->save();

                $this->log()->info('Monipay payment fulfilled', $this->context($locked) + [
                    'source' => $source,
                    'subscription_id' => $subscription->id,
                    'period_ends_at' => $subscription->current_period_ends_at?->toIso8601String(),
                ]);

                return ['result' => 'paid', 'payment' => $locked];
            }

            if (in_array($status, MonipayClient::PENDING_STATUSES, true)) {
                $locked->update(['verify_payload' => $safe]);
                $this->log()->info('Monipay payment still pending', $context + ['provider_status' => $status]);

                return ['result' => 'pending', 'payment' => $locked];
            }

            $final = in_array($status, MonipayClient::ABANDONED_STATUSES, true)
                ? Payment::STATUS_ABANDONED
                : Payment::STATUS_FAILED;

            $locked->update(['status' => $final, 'verify_payload' => $safe]);
            $this->log()->warning('Monipay payment '.$final, $context + ['provider_status' => $status]);

            return ['result' => $final, 'payment' => $locked];
        });
    }

    /**
     * Activate (or extend) the organization's subscription for the paid plan.
     * Paying again for the plan you already have extends from the current
     * period end; switching plans starts a fresh period today.
     */
    protected function fulfil(Payment $payment): Subscription
    {
        $plan = $payment->plan ?? Plan::query()->where('key', $payment->plan_key)->firstOrFail();
        $organization = Organization::query()->findOrFail($payment->organization_id);

        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()
            ->where('organization_id', $organization->id)
            ->where('product', $plan->product)
            ->latest('id')
            ->lockForUpdate()
            ->first();

        $start = now();
        if ($subscription
            && $subscription->plan_id === $plan->id
            && $subscription->status === 'active'
            && $subscription->current_period_ends_at?->isFuture()) {
            $start = $subscription->current_period_ends_at->copy();
        }

        $end = match (strtolower((string) $plan->interval)) {
            'year', 'yearly', 'annual', 'annually' => $start->copy()->addYearNoOverflow(),
            default => $start->copy()->addMonthNoOverflow(),
        };

        $subscription ??= new Subscription([
            'key' => 'sub_'.Str::lower((string) Str::ulid()),
            'organization_id' => $organization->id,
            'seats' => max(1, (int) $organization->seats),
        ]);

        $subscription->fill([
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'product' => $plan->product,
            'status' => 'active',
            'price' => $plan->price,
            'renews_at' => $end->format('M j, Y'),
            'current_period_ends_at' => $end,
        ]);
        $subscription->save();

        // Mirror AdminController::updateSubscription so admin views stay consistent.
        $organization->update([
            'plan' => $plan->name,
            'product' => $plan->product,
            'mrr' => $plan->price,
            'status' => 'active',
        ]);

        return $subscription;
    }

    protected function markFailed(Payment $payment, string $reason): void
    {
        $payment->update([
            'status' => Payment::STATUS_FAILED,
            'meta' => array_merge($payment->meta ?? [], ['failure_reason' => $reason]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(Payment $payment): array
    {
        return [
            'payment_id' => $payment->id,
            'reference' => $payment->reference,
            'monipay_reference' => $payment->provider_reference,
            'trans_id' => $payment->trans_id,
            'organization_id' => $payment->organization_id,
            'plan' => $payment->plan_key,
            'amount' => $payment->amount,
            'status' => $payment->status,
        ];
    }

    public function log(): LoggerInterface
    {
        return Log::channel(config('services.monipay.log_channel') ?: config('logging.default'));
    }

    protected function scalar(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    protected function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
