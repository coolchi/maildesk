<?php

namespace Database\Factories;

use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationRunStep>
 */
class AutomationRunStepFactory extends Factory
{
    protected $model = AutomationRunStep::class;

    public function definition(): array
    {
        return [
            'automation_run_id' => AutomationRun::factory(),
            'position' => 0,
            'type' => 'email',
            'status' => 'pending',
            'config' => ['label' => 'Send welcome email', 'subject' => 'Send welcome email', 'html' => '<p>Send welcome email</p>'],
        ];
    }

    public function delay(int $minutes = 60): static
    {
        return $this->state(fn (): array => [
            'type' => 'delay',
            'config' => [
                'label' => 'Wait 1 hour',
                'duration_minutes' => $minutes,
            ],
        ]);
    }

    public function email(string $label = 'Send welcome email'): static
    {
        return $this->state(fn (): array => [
            'type' => 'email',
            'config' => [
                'label' => $label,
                'subject' => $label,
                'html' => '<p>'.$label.'</p>',
            ],
        ]);
    }
}
