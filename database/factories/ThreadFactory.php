<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Thread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Thread>
 */
class ThreadFactory extends Factory
{
    protected $model = Thread::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'mailbox_id' => null,
            'subject' => fake()->sentence(6),
            'snippet' => fake()->sentence(12),
            'last_message_at' => now()->subHours(fake()->numberBetween(1, 72)),
            'message_count' => 1,
            'is_read' => fake()->boolean(40),
        ];
    }
}
