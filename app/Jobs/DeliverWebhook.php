<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookDeliverer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one webhook event to one endpoint, retrying failed attempts with
 * increasing delays. Every attempt increments the delivery's `attempts` and
 * records the latest response on the same delivery row.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 5;

    /** Seconds to wait before attempt 2, 3, 4 and 5. */
    public const BACKOFF = [30, 120, 600, 1800];

    public int $tries = self::MAX_ATTEMPTS;

    public int $timeout = 30;

    public function __construct(public int $deliveryId) {}

    public function handle(WebhookDeliverer $deliverer): void
    {
        $delivery = WebhookDelivery::query()->with('webhook')->find($this->deliveryId);

        if ($delivery === null || $delivery->status === 'success') {
            return;
        }

        if ($delivery->webhook !== null && ! $delivery->webhook->is_active && $delivery->attempts > 0) {
            // Disabled between retries: stop quietly, the last failure is already recorded.
            return;
        }

        $result = $deliverer->attempt($delivery);

        if ($result['ok']) {
            return;
        }

        $attempts = (int) $delivery->fresh()?->attempts;

        if ($result['retryable'] && $attempts < self::MAX_ATTEMPTS) {
            $this->release(self::BACKOFF[$attempts - 1] ?? self::BACKOFF[count(self::BACKOFF) - 1]);

            return;
        }

        Log::error('Webhook delivery failed permanently', [
            'webhook_id' => $delivery->webhook_id,
            'delivery_id' => $delivery->id,
            'event' => $delivery->event,
            'attempts' => $attempts,
            'status' => $result['status'],
            'error' => $result['error'],
        ]);
    }

    public function failed(?Throwable $e): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if ($delivery !== null && $delivery->status !== 'success') {
            $delivery->forceFill([
                'status' => 'failed',
                'response_body' => $delivery->response_body ?: ($e?->getMessage() ?? 'Delivery job failed.'),
            ])->save();
        }

        Log::error('Webhook delivery job failed', [
            'webhook_id' => $delivery?->webhook_id,
            'delivery_id' => $this->deliveryId,
            'event' => $delivery?->event,
            'error' => $e?->getMessage(),
        ]);
    }
}
