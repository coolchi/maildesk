<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    protected $model = Template::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->words(3, true),
            'subject' => fake()->sentence(4),
            'status' => 'draft',
            'html' => '<p>'.fake()->paragraph().'</p>',
        ];
    }
}
