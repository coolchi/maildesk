<?php

namespace Tests\Feature;

use App\Jobs\SendScheduledMessage;
use App\Models\Domain;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScheduledSendTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_schedule_email(): void
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

        $when = now()->addHour()->toISOString();

        $response = $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('emails.store'), [
                'from' => 'hello@acme.test',
                'to' => 'customer@example.com',
                'subject' => 'Later',
                'html' => '<p>Hi</p>',
                'schedule' => true,
                'schedule_at' => $when,
            ]);

        $message = Message::query()->where('organization_id', $org->id)->first();
        $this->assertNotNull($message);
        $this->assertSame('scheduled', $message->status);
        $this->assertNotNull($message->scheduled_at);
        $response->assertRedirect(route('emails.show', $message->uuid));
    }

    public function test_scheduled_job_sends_due_messages(): void
    {
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);
        $org = Organization::factory()->create([
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
        ]);

        $message = Message::factory()->create([
            'organization_id' => $org->id,
            'direction' => 'outbound',
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
            'subject' => 'Due now',
            'from_email' => 'hello@acme.test',
            'to' => ['customer@example.com'],
        ]);

        (new SendScheduledMessage)->handle(app(EmailService::class));

        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertNull($message->scheduled_at);
    }

    public function test_attachments_are_stored_on_send(): void
    {
        Storage::fake('local');

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

        $file = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('emails.store'), [
                'from' => 'hello@acme.test',
                'to' => 'customer@example.com',
                'subject' => 'With file',
                'html' => '<p>Hi</p>',
                'attachments' => [$file],
            ])
            ->assertRedirect();

        $message = Message::query()->where('subject', 'With file')->first();
        $this->assertNotNull($message);
        $this->assertCount(1, $message->attachments);
        $this->assertSame('notes.txt', $message->attachments->first()->filename);
    }
}
