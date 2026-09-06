<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComposeEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_send_email_through_workspace_provider(): void
    {
        $user = User::factory()->create();
        $provider = MailProvider::factory()->create([
            'key' => 'resend',
            'driver' => 'resend',
            'status' => 'active',
        ]);
        $org = Organization::factory()->create([
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);
        Domain::factory()->verified()->create([
            'organization_id' => $org->id,
            'name' => 'acme.test',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('emails.store'), [
                'from' => 'hello@acme.test',
                'to' => 'customer@example.com',
                'subject' => 'Welcome aboard',
                'html' => '<p>Hello from MailDesk</p>',
                'tags' => ['onboarding'],
            ]);

        $message = Message::query()->where('organization_id', $org->id)->first();
        $this->assertNotNull($message);
        $this->assertSame('sent', $message->status);
        $this->assertSame('Welcome aboard', $message->subject);
        $this->assertSame('hello@acme.test', $message->from_email);
        $this->assertNotNull($message->provider_message_id);

        $response->assertRedirect(route('emails.show', $message->uuid));
    }

    public function test_send_requires_verified_from_domain_when_workspace_has_verified_domains(): void
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

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->from(route('emails'))
            ->post(route('emails.store'), [
                'from' => 'hello@evil.test',
                'to' => 'customer@example.com',
                'subject' => 'Nope',
                'html' => '<p>x</p>',
            ])
            ->assertRedirect(route('emails'))
            ->assertSessionHasErrors('from');

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_send_is_blocked_without_active_provider(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create([
            'mail_provider_id' => null,
            'default_provider' => 'resend',
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->from(route('emails'))
            ->post(route('emails.store'), [
                'from' => 'hello@acme.test',
                'to' => 'customer@example.com',
                'subject' => 'Hello',
                'html' => '<p>Hi</p>',
            ])
            ->assertRedirect(route('emails'))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_scheduled_send_is_rejected(): void
    {
        $user = User::factory()->create();
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);
        $org = Organization::factory()->create([
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->from(route('emails'))
            ->post(route('emails.store'), [
                'from' => 'hello@acme.test',
                'to' => 'customer@example.com',
                'subject' => 'Later',
                'html' => '<p>Hi</p>',
                'schedule' => true,
            ])
            ->assertRedirect(route('emails'))
            ->assertSessionHasErrors('schedule_at');

        $this->assertDatabaseCount('messages', 0);
    }
}
