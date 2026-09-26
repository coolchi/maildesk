<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplatePublishTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Organization, 2: Template}
     */
    private function workspaceWithTemplate(): array
    {
        config(['maildesk.fake_send' => true]);

        $user = User::factory()->create(['email' => 'owner@acme.test']);
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);
        $org = Organization::factory()->create([
            'status' => 'active',
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);
        Domain::factory()->verified()->create([
            'organization_id' => $org->id,
            'name' => 'acme.test',
        ]);
        $template = Template::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Welcome',
            'subject' => 'Welcome aboard',
            'html' => '<p>Hello</p>',
            'status' => 'draft',
        ]);

        return [$user, $org, $template];
    }

    public function test_owner_can_publish_and_unpublish_template(): void
    {
        [$user, $org, $template] = $this->workspaceWithTemplate();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('templates.publish', $template))
            ->assertRedirect();

        $this->assertSame('published', $template->fresh()->status);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('templates.publish', $template))
            ->assertRedirect();

        $this->assertSame('draft', $template->fresh()->status);
    }

    public function test_owner_can_send_template_test_to_self(): void
    {
        [$user, $org, $template] = $this->workspaceWithTemplate();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('templates.test', $template))
            ->assertRedirect()
            ->assertSessionHas('success');

        $message = Message::query()->where('organization_id', $org->id)->firstOrFail();
        $this->assertSame('[Test] Welcome aboard', $message->subject);
        $to = $message->to[0] ?? null;
        $email = is_array($to) ? ($to['email'] ?? null) : $to;
        $this->assertSame('owner@acme.test', $email);
    }
}
