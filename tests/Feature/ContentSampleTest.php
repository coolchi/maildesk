<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\Template;
use App\Models\User;
use App\Support\ContentSamples;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentSampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_includes_the_business_samples_and_their_images(): void
    {
        $samples = ContentSamples::all();
        $this->assertCount(13, $samples);

        $keys = array_column($samples, 'key');
        foreach (['welcome', 'invoice', 'newsletter', 'renewal', 'event', 'follow-up'] as $key) {
            $this->assertContains($key, $keys);
        }

        $welcome = ContentSamples::find('welcome');
        $this->assertNotNull($welcome);
        $this->assertStringContainsString('/images/templates/welcome-hero.png', $welcome['html']);
        $this->assertStringContainsString('{{first_name}}', $welcome['html']);
        $this->assertNull(ContentSamples::find('missing'));
    }

    public function test_using_a_sample_creates_an_editable_template(): void
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

        $sample = ContentSamples::find('renewal');
        $this->assertNotNull($sample);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('templates.samples.edit', ['sample' => 'renewal']))
            ->assertOk();

        $this->assertSame(0, Template::query()->where('organization_id', $org->id)->count());

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('templates.samples.store'), [
                'sample_key' => 'renewal',
                'name' => $sample['name'],
                'subject' => $sample['subject'],
                'html' => $sample['html'],
                'design_key' => $sample['design_key'],
            ])
            ->assertRedirect();

        $this->assertSame(0, Template::query()->where('organization_id', $org->id)->count());

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('templates.samples.store'), [
                'sample_key' => 'renewal',
                'name' => $sample['name'],
                'subject' => 'Changed subject',
                'html' => $sample['html'],
                'design_key' => $sample['design_key'],
            ])
            ->assertRedirect();

        $template = Template::query()->where('organization_id', $org->id)->firstOrFail();
        $this->assertSame('Renewal reminder', $template->name);
        $this->assertSame('midnight', $template->design_key);
        $this->assertStringContainsString('renewal.png', $template->html);
        $this->assertSame('Changed subject', $template->subject);
        $this->assertSame('draft', $template->status);
    }
}
