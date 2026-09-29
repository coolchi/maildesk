<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileContactTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->org = Organization::factory()->create();
        $this->org->users()->attach($this->user->id, ['role' => 'owner']);

        $this->token = $this->user->createToken('Test')->plainTextToken;
    }

    private function api()
    {
        return $this->withToken($this->token)
            ->withHeaders(['X-Workspace-Id' => $this->org->id]);
    }

    public function test_contacts_list(): void
    {
        Contact::factory()->count(5)->create([
            'organization_id' => $this->org->id,
        ]);

        $response = $this->api()->getJson('/api/v1/mobile/contacts');

        $response->assertOk()
            ->assertJsonStructure([
                'contacts' => [['id', 'email', 'first_name', 'last_name', 'name', 'company']],
                'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        $this->assertCount(5, $response->json('contacts'));
    }

    public function test_contacts_search(): void
    {
        Contact::factory()->create([
            'organization_id' => $this->org->id,
            'email' => 'john@example.com',
            'first_name' => 'John',
        ]);

        Contact::factory()->create([
            'organization_id' => $this->org->id,
            'email' => 'jane@example.com',
            'first_name' => 'Jane',
        ]);

        $response = $this->api()->getJson('/api/v1/mobile/contacts?search=john');

        $response->assertOk();
        $this->assertCount(1, $response->json('contacts'));
        $this->assertSame('john@example.com', $response->json('contacts.0.email'));
    }

    public function test_contacts_pagination(): void
    {
        Contact::factory()->count(30)->create([
            'organization_id' => $this->org->id,
        ]);

        $response = $this->api()->getJson('/api/v1/mobile/contacts?per_page=10');

        $response->assertOk();
        $this->assertCount(10, $response->json('contacts'));
        $this->assertSame(3, $response->json('pagination.last_page'));
    }

    public function test_create_contact(): void
    {
        $response = $this->api()->postJson('/api/v1/mobile/contacts', [
            'email' => 'new@example.com',
            'first_name' => 'New',
            'last_name' => 'Contact',
            'company' => 'Acme Inc',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['contact' => ['id', 'email', 'first_name', 'last_name']]);

        $this->assertDatabaseHas('contacts', [
            'organization_id' => $this->org->id,
            'email' => 'new@example.com',
            'first_name' => 'New',
            'last_name' => 'Contact',
            'company' => 'Acme Inc',
        ]);
    }

    public function test_create_contact_validation(): void
    {
        $response = $this->api()->postJson('/api/v1/mobile/contacts', [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_cannot_access_other_workspace_contacts(): void
    {
        $otherOrg = Organization::factory()->create();
        Contact::factory()->create([
            'organization_id' => $otherOrg->id,
        ]);

        $response = $this->api()->getJson('/api/v1/mobile/contacts');

        $response->assertOk();
        $this->assertCount(0, $response->json('contacts'));
    }
}
