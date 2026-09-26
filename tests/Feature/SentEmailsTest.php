<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SentEmailsTest extends TestCase
{
    use RefreshDatabase;

    private function member(): array
    {
        $user = User::factory()->create();
        $provider = MailProvider::query()->where('key', 'resend')->first()
            ?? MailProvider::factory()->create([
                'key' => 'resend',
                'driver' => 'resend',
                'status' => 'active',
            ]);
        $org = Organization::factory()->create([
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $org];
    }

    private function message(Organization $org, array $attributes): Message
    {
        return Message::factory()->create(array_merge([
            'organization_id' => $org->id,
            'direction' => 'outbound',
            'status' => 'sent',
            'from_email' => 'hello@acme.test',
            'to' => ['customer@example.com'],
            'subject' => 'Hello',
            'sent_at' => now(),
        ], $attributes));
    }

    public function test_sent_page_lists_only_outbound_mail_with_status(): void
    {
        [$user, $org] = $this->member();
        $this->message($org, ['subject' => 'Delivered one', 'status' => 'delivered', 'to' => ['a@example.com'], 'sent_at' => now()->subHour()]);
        $this->message($org, ['subject' => 'Failed one', 'status' => 'failed', 'to' => ['b@example.com'], 'meta' => ['error' => 'Domain not verified']]);
        $this->message($org, ['subject' => 'Incoming', 'direction' => 'inbound', 'status' => 'received']);
        [, $other] = $this->member();
        $this->message($other, ['subject' => 'Someone else']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('sent'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sent/Index')
                ->has('emails', 2)
                ->where('emails.0.subject', 'Failed one')
                ->where('emails.0.to', 'b@example.com')
                ->where('emails.0.status', 'failed')
                ->where('emails.0.error', 'Domain not verified')
                ->where('emails.1.subject', 'Delivered one')
                ->where('emails.1.status', 'delivered')
                ->where('emails.1.error', null)
                ->where('counts.all', 2)
                ->where('counts.delivered', 1)
                ->where('counts.failed', 1)
                ->where('pagination.total', 2));
    }

    public function test_sent_page_filters_by_status_and_search(): void
    {
        [$user, $org] = $this->member();
        $this->message($org, ['subject' => 'Invoice ready', 'status' => 'delivered']);
        $this->message($org, ['subject' => 'Bounced invoice', 'status' => 'bounced', 'to' => ['gone@example.com']]);
        $this->message($org, ['subject' => 'Welcome', 'status' => 'delivered']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('sent', ['status' => 'failed']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('emails', 1)
                ->where('emails.0.subject', 'Bounced invoice')
                ->where('filters.status', 'failed'));

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('sent', ['q' => 'invoice']))
            ->assertInertia(fn (Assert $page) => $page->has('emails', 2));

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('sent', ['q' => 'gone@']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('emails', 1)
                ->where('emails.0.to', 'gone@example.com'));
    }

    public function test_compose_route_still_works(): void
    {
        [$user, $org] = $this->member();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('compose'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Compose/Index'));
    }

    public function test_floating_compose_send_stays_on_current_page(): void
    {
        [$user, $org] = $this->member();
        Domain::factory()->verified()->create([
            'organization_id' => $org->id,
            'name' => 'acme.test',
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->from(route('inbox'))
            ->post(route('emails.store'), [
                'from' => 'hello@acme.test',
                'to' => 'customer@example.com',
                'subject' => 'From the panel',
                'html' => '<p>Hi</p>',
                'stay' => 1,
            ])
            ->assertRedirect(route('inbox'))
            ->assertSessionHas('success', 'Email sent.');

        $this->assertDatabaseHas('messages', ['subject' => 'From the panel', 'direction' => 'outbound']);
    }
}
