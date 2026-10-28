<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_search_returns_recent_people_chats_and_mail_first(): void
    {
        [$user, $organization, $token] = $this->member();
        $grace = User::factory()->create(['name' => 'Grace Hopper']);
        $organization->users()->attach($grace->id, ['role' => 'member']);

        $this->asApp($token, $organization)->postJson('/api/app/conversations', [
            'type' => 'direct',
            'user_ids' => [$grace->id],
        ])->assertSuccessful();

        $conversationId = $this->asApp($token, $organization)->getJson('/api/app/conversations')->json('data.0.id');
        $this->asApp($token, $organization)->postJson("/api/app/conversations/{$conversationId}/messages", [
            'body' => 'Hello Grace',
        ])->assertCreated();

        Thread::factory()->create([
            'organization_id' => $organization->id,
            'subject' => 'Invoice 14',
            'snippet' => 'Please pay soon',
            'is_read' => false,
            'last_message_at' => now(),
        ]);

        $this->asApp($token, $organization)->getJson('/api/app/search')
            ->assertOk()
            ->assertJsonPath('query', '')
            ->assertJsonPath('people.0.name', 'Grace Hopper')
            ->assertJsonPath('chats.0.name', 'Grace Hopper')
            ->assertJsonPath('mail.0.subject', 'Invoice 14');
    }

    public function test_search_matches_people_chats_and_mail(): void
    {
        [$user, $organization, $token] = $this->member();
        $grace = User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);
        $organization->users()->attach($grace->id, ['role' => 'member']);
        Contact::factory()->create([
            'organization_id' => $organization->id,
            'email' => 'billing@vendor.test',
            'first_name' => 'Vendor',
            'last_name' => 'Billing',
        ]);

        $conversationId = $this->asApp($token, $organization)->postJson('/api/app/conversations', [
            'type' => 'direct',
            'user_ids' => [$grace->id],
        ])->json('data.id');
        $this->asApp($token, $organization)->postJson("/api/app/conversations/{$conversationId}/messages", [
            'body' => 'Need the launch checklist',
        ])->assertCreated();

        Thread::factory()->create([
            'organization_id' => $organization->id,
            'subject' => 'Launch checklist',
            'snippet' => 'Steps for Friday',
            'last_message_at' => now(),
        ]);
        Thread::factory()->create([
            'organization_id' => $organization->id,
            'subject' => 'Unrelated',
            'snippet' => 'Ignore me',
            'last_message_at' => now()->subDay(),
        ]);

        $response = $this->asApp($token, $organization)->getJson('/api/app/search?q=launch')
            ->assertOk()
            ->assertJsonPath('query', 'launch');

        $this->assertSame('Need the launch checklist', $response->json('chats.0.preview'));
        $this->assertSame('Launch checklist', $response->json('mail.0.subject'));
        $this->assertCount(1, $response->json('mail'));

        $people = $this->asApp($token, $organization)->getJson('/api/app/search?q=vendor')
            ->assertOk()
            ->json('people');

        $this->assertTrue(collect($people)->contains(fn ($person) => $person['email'] === 'billing@vendor.test'));
    }

    public function test_search_stays_inside_the_workspace(): void
    {
        [, $organization, $token] = $this->member();
        $other = Organization::factory()->create();
        Thread::factory()->create([
            'organization_id' => $other->id,
            'subject' => 'Secret launch',
            'snippet' => 'Do not show',
            'last_message_at' => now(),
        ]);

        $this->asApp($token, $organization)->getJson('/api/app/search?q=launch')
            ->assertOk()
            ->assertJsonPath('mail', []);
    }

    /**
     * @return array{0: User, 1: Organization, 2: string}
     */
    private function member(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $organization, $user->createToken('test')->plainTextToken];
    }

    private function asApp(string $token, Organization $organization): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->withHeader('X-Organization-Id', (string) $organization->id);
    }
}
