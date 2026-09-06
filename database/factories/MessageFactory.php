<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'organization_id' => Organization::factory(),
            'thread_id' => null,
            'mailbox_id' => null,
            'direction' => 'outbound',
            'status' => 'delivered',
            'provider' => 'resend',
            'from_email' => 'noreply@'.fake()->domainName(),
            'from_name' => fake()->company(),
            'to' => [['email' => fake()->safeEmail()]],
            'subject' => fake()->sentence(5),
            'text_body' => fake()->paragraph(),
            'html_body' => '<p>'.fake()->paragraph().'</p>',
            'sent_at' => now()->subHours(fake()->numberBetween(1, 48)),
        ];
    }

    public function inbound(): static
    {
        return $this->state(fn () => [
            'direction' => 'inbound',
            'status' => 'received',
            'received_at' => now()->subHours(2),
            'sent_at' => null,
        ]);
    }

    public function bounced(): static
    {
        return $this->state(fn () => [
            'status' => 'bounced',
        ]);
    }
}
