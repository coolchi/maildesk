<?php

namespace Database\Factories;

use App\Models\MailDraft;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailDraft>
 */
class MailDraftFactory extends Factory
{
    protected $model = MailDraft::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'mailbox_id' => null,
            'thread_id' => null,
            'from' => fake()->safeEmail(),
            'to' => fake()->safeEmail(),
            'cc' => null,
            'bcc' => null,
            'subject' => fake()->sentence(4),
            'html' => '<p>'.fake()->paragraph().'</p>',
        ];
    }
}
