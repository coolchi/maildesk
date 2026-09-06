<?php

namespace App\Jobs;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

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
            ->filter(function (Webhook $webhook) {
                $events = $webhook->events ?? [];

                return in_array($this->event, $events, true);
            });

        foreach ($webhooks as $webhook) {
            $body = [
                'event' => $this->event,
                'data' => $this->payload,
                'sent_at' => now()->toIso8601String(),
            ];

            $delivery = WebhookDelivery::query()->create([
                'webhook_id' => $webhook->id,
                'event' => $this->event,
                'payload' => $body,
                'status' => 'pending',
                'attempts' => 1,
            ]);

            try {
                $response = Http::timeout(10)
                    ->withHeaders([
                        'X-MailDesk-Event' => $this->event,
                        'X-MailDesk-Signature' => hash_hmac('sha256', (string) json_encode($body), (string) $webhook->secret),
                    ])
                    ->post($webhook->url, $body);

                $delivery->update([
                    'response_status' => $response->status(),
                    'response_body' => Str::limit($response->body(), 2000),
                    'status' => $response->successful() ? 'success' : 'failed',
                    'delivered_at' => now(),
                ]);
            } catch (Throwable $e) {
                $delivery->update([
                    'response_status' => null,
                    'response_body' => $e->getMessage(),
                    'status' => 'failed',
                    'delivered_at' => now(),
                ]);
            }
        }
    }
}
