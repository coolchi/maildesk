<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Webhook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Webhook>
 */
class WebhookFactory extends Factory
{
    protected $model = Webhook::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'url' => 'https://hooks.example.com/'.fake()->uuid(),
            'secret' => Webhook::generateSecret(),
            'events' => ['email.delivered', 'email.bounced'],
            'is_active' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
