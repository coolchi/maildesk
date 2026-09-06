<?php

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    public function definition(): array
    {
        $plain = 'md_'.Str::random(40);

        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Production', 'Staging', 'Local Dev']),
            'key_prefix' => substr($plain, 0, 12),
            'key_hash' => hash('sha256', $plain),
            'abilities' => ['*'],
            'last_used_at' => fake()->optional()->dateTimeBetween('-2 weeks'),
            'expires_at' => null,
        ];
    }
}
