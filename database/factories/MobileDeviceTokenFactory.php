<?php

namespace Database\Factories;

use App\Models\MobileDeviceToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MobileDeviceToken>
 */
class MobileDeviceTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token' => 'ExponentPushToken['.$this->faker->regexify('[A-Za-z0-9]{22}').']',
            'platform' => $this->faker->randomElement(['ios', 'android']),
            'last_used_at' => now(),
        ];
    }

    public function ios(): static
    {
        return $this->state(fn () => ['platform' => 'ios']);
    }

    public function android(): static
    {
        return $this->state(fn () => ['platform' => 'android']);
    }
}
