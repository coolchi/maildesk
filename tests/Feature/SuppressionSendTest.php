<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuppressionSendTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_to_suppressed_address_is_blocked(): void
    {
        $user = User::factory()->create();
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);
        $org = Organization::factory()->create([
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);
        Domain::factory()->verified()->create([
            'organization_id' => $org->id,
            'name' => 'acme.test',
        ]);
        Suppression::factory()->create([
            'organization_id' => $org->id,
            'email' => 'blocked@example.com',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('emails.store'), [
                'from' => 'hello@acme.test',
                'to' => 'blocked@example.com',
                'subject' => 'Nope',
                'html' => '<p>Hi</p>',
            ]);

        $message = Message::query()->where('organization_id', $org->id)->first();
        $this->assertNotNull($message);
        $this->assertSame('suppressed', $message->status);
        $response->assertRedirect(route('emails.show', $message->uuid));
        $response->assertSessionHas('error');
    }
}
