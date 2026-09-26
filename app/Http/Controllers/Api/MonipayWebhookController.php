<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Billing\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Public endpoint Monipay POSTs events to.
 *
 * Signature: x-monipay-signature = hex HMAC-SHA512 of the raw body keyed with
 * MONIPAY_WEBHOOK_SECRET (falls back to MONIPAY_SECRET_KEY), compared with
 * hash_equals. As defence in depth a valid charge.success is still verified
 * server-side (GET /transaction/verify) before the plan is fulfilled.
 *
 * 2xx = done (including ignored events), 401 = bad signature,
 * 5xx = not configured / retry later.
 */
class MonipayWebhookController extends Controller
{
    public function __invoke(Request $request, BillingService $billing): JsonResponse
    {
        $event = $request->json('event') ?? $request->json('type');
        $context = ['ip' => $request->ip(), 'event' => is_string($event) ? $event : null];

        if (! $billing->monipay->webhookSecret() && ! app()->environment(['local', 'testing'])) {
            $billing->log()->critical('Monipay webhook rejected: webhook secret is not configured', $context);

            return response()->json(['message' => 'Payment webhooks are not configured.'], 503);
        }

        if (! $billing->monipay->validSignature($request->getContent(), $request->header('x-monipay-signature'))) {
            $billing->log()->warning('Monipay webhook rejected: invalid signature', $context);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        if ($event !== 'charge.success') {
            $billing->log()->info('Monipay webhook ignored', $context);

            return response()->json(['status' => 'ignored']);
        }

        $reference = collect(['data.reference', 'reference', 'data.order_id', 'data.trans_id', 'order_id', 'trans_id'])
            ->map(fn (string $key) => $request->json($key))
            ->first(fn ($value) => is_scalar($value) && trim((string) $value) !== '');

        $payment = $billing->findByReference(is_scalar($reference) ? (string) $reference : null);

        if (! $payment) {
            $billing->log()->info('Monipay webhook for unknown payment ignored', $context + ['monipay_reference' => $reference]);

            return response()->json(['status' => 'ignored']);
        }

        try {
            $outcome = $billing->verifyAndFulfil($payment, 'webhook');
        } catch (Throwable $e) {
            report($e);
            $billing->log()->error('Monipay webhook processing failed', $context + $billing->context($payment) + ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Processing failed.'], 500);
        }

        if ($outcome['result'] === 'error') {
            return response()->json(['message' => 'Could not verify the payment yet.'], 503);
        }

        return response()->json([
            'status' => $outcome['result'],
            'reference' => $outcome['payment']->reference,
        ]);
    }
}
