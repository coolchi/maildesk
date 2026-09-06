<?php

namespace Database\Factories;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    public function definition(): array
    {
        return [
            'webhook_id' => Webhook::factory(),
            'event' => fake()->randomElement(['email.delivered', 'email.bounced', 'email.sent']),
            'payload' => ['id' => fake()->uuid()],
            'response_status' => 200,
            'response_body' => 'ok',
            'status' => 'success',
            'attempts' => 1,
            'delivered_at' => now()->subMinutes(12),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'response_status' => 502,
            'response_body' => 'Bad Gateway',
        ]);
    }
}
