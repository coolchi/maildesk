<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationHost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationHost>
 */
class OrganizationHostFactory extends Factory
{
    protected $model = OrganizationHost::class;

    public function definition(): array
    {
        $sub = fake()->unique()->domainWord();

        return [
            'organization_id' => Organization::factory(),
            'subdomain' => $sub,
            'host' => $sub.'.maildesk.test',
            'status' => 'active',
            'ssl' => true,
            'is_custom' => false,
        ];
    }
}
