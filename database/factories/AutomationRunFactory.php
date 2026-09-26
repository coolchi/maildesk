<?php

namespace Database\Factories;

use App\Models\Automation;
use App\Models\AutomationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationRun>
 */
class AutomationRunFactory extends Factory
{
    protected $model = AutomationRun::class;

    public function definition(): array
    {
        return [
            'automation_id' => Automation::factory(),
            'organization_id' => function (array $attributes) {
                return Automation::query()->find($attributes['automation_id'])?->organization_id
                    ?? Automation::factory()->create()->organization_id;
            },
            'contact_email' => fake()->safeEmail(),
            'status' => 'pending',
            'current_step_position' => 0,
            'payload' => [],
        ];
    }

    public function waiting(): static
    {
        return $this->state(fn (): array => [
            'status' => 'waiting',
            'due_at' => now()->addHour(),
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'completed_at' => now(),
        ]);
    }
}
