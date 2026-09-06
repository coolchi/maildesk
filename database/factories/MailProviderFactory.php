<?php

namespace Database\Factories;

use App\Models\MailProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailProvider>
 */
class MailProviderFactory extends Factory
{
    protected $model = MailProvider::class;

    public function definition(): array
    {
        $driver = fake()->randomElement(['resend', 'postmark', 'sendgrid', 'ses', 'smtp']);

        return [
            'key' => $driver.'_'.fake()->unique()->numerify('###'),
            'name' => ucfirst($driver).' '.fake()->word(),
            'driver' => $driver,
            'type' => $driver === 'smtp' ? 'smtp' : 'api',
            'status' => 'active',
            'is_default' => false,
            'api_base' => $driver === 'smtp' ? null : 'https://api.example.com',
            'regions' => ['us-east-1'],
            'features' => ['Transactional'],
            'description' => fake()->sentence(),
            'config' => [
                ['key' => 'API_KEY', 'value' => 'test_key', 'secret' => true],
            ],
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }
}
