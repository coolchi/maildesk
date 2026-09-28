<?php

namespace Tests\Feature;

use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Template;
use App\Models\User;
use App\Services\BroadcastService;
use App\Support\DesignTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DesignTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_has_five_designs_and_plain_html_is_left_alone(): void
    {
        $this->assertCount(5, DesignTemplates::all());
        $this->assertSame(
            '<p>Hello</p>',
            DesignTemplates::wrap(null, '<p>Hello</p>', 'MailDesk'),
        );
        $this->assertSame(
            '<p>Hello</p>',
            DesignTemplates::wrap('not-a-design', '<p>Hello</p>', 'MailDesk'),
        );

        $wrapped = DesignTemplates::wrap('linen', '<p>Hello</p>', 'MailDesk');
        $this->assertStringContainsString('data-md-design="linen"', $wrapped);
        $this->assertStringContainsString('color:#c2410c', $wrapped);
        $this->assertStringContainsString('<p>Hello</p>', $wrapped);
        $this->assertStringContainsString('MailDesk', $wrapped);

        DesignTemplates::setAccent($org = Organization::factory()->create(['name' => 'MailDesk']), 'linen', '#112233');
        $colored = DesignTemplates::wrap('linen', '<p>Hello</p>', 'MailDesk', $org);
        $this->assertStringContainsString('color:#112233', $colored);
        $this->assertSame($wrapped, DesignTemplates::wrap('aurora', $wrapped, 'MailDesk'));
    }

    public function test_compose_uses_the_workspace_default_and_plain_mail_when_unset(): void
    {
        [$user, $org] = $this->workspace();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('emails.store'), [
                'from' => 'hello@acme.test',
                'to' => 'customer@example.com',
                'subject' => 'Plain',
                'html' => '<p>Just the words</p>',
            ]);

        $plain = Message::query()->where('subject', 'Plain')->firstOrFail();
        $this->assertStringNotContainsString('data-md-design=', (string) $plain->html_body);

        DesignTemplates::setDefault($org, 'midnight');

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('emails.store'), [
                'from' => 'hello@acme.test',
                'to' => 'customer@example.com',
                'subject' => 'Framed',
                'html' => '<p>Inside the card</p>',
            ]);

        $framed = Message::query()->where('subject', 'Framed')->firstOrFail();
        $this->assertStringContainsString('data-md-design="midnight"', (string) $framed->html_body);
        $this->assertStringContainsString('<p>Inside the card</p>', (string) $framed->html_body);
        $this->assertStringContainsString($org->name, (string) $framed->html_body);
        $this->assertSame('midnight', $framed->meta['design'] ?? null);
    }

    public function test_workspace_can_set_and_clear_the_default_design(): void
    {
        [$user, $org] = $this->workspace();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->put(route('templates.design-default'), ['design_key' => 'signal'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('signal', DesignTemplates::defaultKey($org->fresh()));

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->put(route('templates.design-default'), ['design_key' => null])
            ->assertRedirect();

        $this->assertNull(DesignTemplates::defaultKey($org->fresh()));
    }

    public function test_content_template_keeps_its_design_and_a_test_send_uses_it(): void
    {
        [$user, $org] = $this->workspace();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('templates.store'), [
                'name' => 'Welcome',
                'subject' => 'Hello',
                'html' => '<p>Welcome aboard</p>',
                'design_key' => 'editorial',
            ])
            ->assertRedirect();

        $template = Template::query()->firstOrFail();
        $this->assertSame('editorial', $template->design_key);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('templates.test', $template))
            ->assertRedirect()
            ->assertSessionHas('success');

        $message = Message::query()->firstOrFail();
        $this->assertStringContainsString('data-md-design="editorial"', (string) $message->html_body);
        $this->assertStringContainsString('<p>Welcome aboard</p>', (string) $message->html_body);
    }

    public function test_broadcast_frames_each_recipient_when_a_design_is_set(): void
    {
        [$user, $org] = $this->workspace();
        Contact::factory()->create([
            'organization_id' => $org->id,
            'email' => 'ann@example.com',
            'first_name' => 'Ann',
        ]);

        $broadcast = Broadcast::factory()->create([
            'organization_id' => $org->id,
            'subject' => 'News',
            'html' => '<p>Hello {{first_name}}</p>',
            'design_key' => 'midnight',
            'from' => 'hello@acme.test',
            'status' => 'queued',
            'audience' => 'all',
        ]);

        $contact = Contact::query()->where('email', 'ann@example.com')->firstOrFail();
        $recipient = BroadcastRecipient::query()->create([
            'broadcast_id' => $broadcast->id,
            'contact_id' => $contact->id,
            'email' => 'ann@example.com',
            'status' => 'pending',
        ]);

        app(BroadcastService::class)->sendTo($recipient);

        $message = Message::query()->firstOrFail();
        $this->assertStringContainsString('data-md-design="midnight"', (string) $message->html_body);
        $this->assertStringContainsString('Hello Ann', (string) $message->html_body);
        $this->assertStringContainsString('Unsubscribe', (string) $message->html_body);
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function workspace(): array
    {
        $user = User::factory()->create();
        $provider = MailProvider::factory()->create([
            'key' => 'array_'.uniqid(),
            'driver' => 'array',
            'status' => 'active',
        ]);
        $org = Organization::factory()->create([
            'name' => 'MailDesk',
            'mail_provider_id' => $provider->id,
            'default_provider' => 'array',
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);
        Domain::factory()->verified()->create([
            'organization_id' => $org->id,
            'name' => 'acme.test',
        ]);

        return [$user, $org];
    }
}
