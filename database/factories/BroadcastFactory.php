<?php

namespace Database\Factories;

use App\Models\Broadcast;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Broadcast>
 */
class BroadcastFactory extends Factory
{
    protected $model = Broadcast::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->words(3, true),
            'subject' => fake()->sentence(5),
            'html' => '<p>'.fake()->paragraph().'</p>',
            'status' => 'draft',
            'sent_at' => null,
        ];
    }
}
