<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Suppression;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_indexes_are_tenant_isolated(): void
    {
        $user = User::factory()->create();
        $own = Organization::factory()->create();
        $other = Organization::factory()->create();
        $own->users()->attach($user->id, ['role' => 'owner']);

        Template::factory()->create(['organization_id' => $own->id, 'name' => 'Own template']);
        Template::factory()->create(['organization_id' => $other->id, 'name' => 'Other template']);
        Broadcast::factory()->create(['organization_id' => $own->id, 'name' => 'Own broadcast']);
        Broadcast::factory()->create(['organization_id' => $other->id, 'name' => 'Other broadcast']);
        Contact::factory()->create(['organization_id' => $own->id, 'email' => 'own@example.com']);
        Contact::factory()->create(['organization_id' => $other->id, 'email' => 'other@example.com']);
        Suppression::factory()->create(['organization_id' => $own->id, 'email' => 'blocked@example.com']);
        Suppression::factory()->create(['organization_id' => $other->id, 'email' => 'foreign@example.com']);
        Automation::factory()->create(['organization_id' => $own->id, 'name' => 'Own auto']);
        Automation::factory()->create(['organization_id' => $other->id, 'name' => 'Other auto']);

        $session = ['current_organization_id' => $own->id];

        $this->actingAs($user)->withSession($session)
            ->get(route('templates'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('templates', 1)->where('templates.0.name', 'Own template'));

        $this->actingAs($user)->withSession($session)
            ->get(route('broadcasts'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('broadcasts', 1)->where('broadcasts.0.name', 'Own broadcast'));

        $this->actingAs($user)->withSession($session)
            ->get(route('audience'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('contacts', 1)->where('contacts.0.email', 'own@example.com'));

        $this->actingAs($user)->withSession($session)
            ->get(route('suppressions'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('suppressions', 1)->where('suppressions.0.email', 'blocked@example.com'));

        $this->actingAs($user)->withSession($session)
            ->get(route('automations'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('automations', 1)->where('automations.0.name', 'Own auto'));
    }

    public function test_template_update_and_foreign_template_is_not_found(): void
    {
        $user = User::factory()->create();
        $own = Organization::factory()->create();
        $other = Organization::factory()->create();
        $own->users()->attach($user->id, ['role' => 'owner']);

        $template = Template::factory()->create(['organization_id' => $own->id]);
        $foreign = Template::factory()->create(['organization_id' => $other->id]);

        $session = ['current_organization_id' => $own->id];

        $this->actingAs($user)->withSession($session)
            ->put(route('templates.update', $template), [
                'name' => 'Updated',
                'subject' => 'Hi',
                'html' => '<p>Hi</p>',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('templates', ['id' => $template->id, 'name' => 'Updated']);

        $this->actingAs($user)->withSession($session)
            ->put(route('templates.update', $foreign), [
                'name' => 'Nope',
                'subject' => 'x',
                'html' => '<p>x</p>',
            ])
            ->assertNotFound();
    }

    public function test_automation_status_toggle_and_contact_store(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $automation = Automation::factory()->create([
            'organization_id' => $org->id,
            'status' => 'paused',
        ]);

        $session = ['current_organization_id' => $org->id];

        $this->actingAs($user)->withSession($session)
            ->put(route('automations.update', $automation), ['status' => 'enabled'])
            ->assertRedirect();

        $this->assertDatabaseHas('automations', [
            'id' => $automation->id,
            'status' => 'active',
        ]);

        $this->actingAs($user)->withSession($session)
            ->post(route('audience.store'), ['email' => 'new@example.com'])
            ->assertRedirect();

        $this->assertDatabaseHas('contacts', [
            'organization_id' => $org->id,
            'email' => 'new@example.com',
        ]);
    }
}
