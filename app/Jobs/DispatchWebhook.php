<?php

namespace App\Jobs;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fans an event out to every enabled endpoint subscribed to it: one
 * delivery row per endpoint, each sent (and retried) by DeliverWebhook.
 */
class DispatchWebhook implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $organizationId,
        public string $event,
        public array $payload = [],
    ) {}

    public function handle(): void
    {
        $webhooks = Webhook::query()
            ->where('organization_id', $this->organizationId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (Webhook $webhook) => in_array($this->event, $webhook->events ?? [], true));

        foreach ($webhooks as $webhook) {
            $delivery = WebhookDelivery::query()->create([
                'webhook_id' => $webhook->id,
                'event' => $this->event,
                'payload' => [
                    'event' => $this->event,
                    'data' => $this->payload,
                    'sent_at' => now()->toIso8601String(),
                ],
                'status' => 'pending',
                'attempts' => 0,
            ]);

            DeliverWebhook::dispatch($delivery->id);
        }
    }
}
