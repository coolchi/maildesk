<?php

namespace Database\Factories;

use App\Models\Mailbox;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mailbox>
 */
class MailboxFactory extends Factory
{
    protected $model = Mailbox::class;

    public function definition(): array
    {
        $local = fake()->unique()->userName();

        return [
            'organization_id' => Organization::factory(),
            'domain_id' => null,
            'email' => $local.'@'.fake()->domainName(),
            'display_name' => fake()->name(),
            'type' => 'shared',
            'role' => 'staff',
            'status' => 'active',
            'inbox' => true,
            'transactional' => false,
            'marketing' => false,
            'send_limit' => 1000,
        ];
    }
}
