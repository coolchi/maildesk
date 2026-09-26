<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InboxSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Organization}
     */
    private function workspace(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $org];
    }

    public function test_sync_returns_unread_count_and_cursor_for_the_current_workspace(): void
    {
        [$user, $org] = $this->workspace();
        $other = Organization::factory()->create();

        Thread::factory()->create([
            'organization_id' => $org->id,
            'is_read' => false,
            'message_count' => 2,
            'last_message_at' => now()->subMinute(),
        ]);
        Thread::factory()->create([
            'organization_id' => $org->id,
            'is_read' => true,
            'message_count' => 1,
            'last_message_at' => now()->subHour(),
        ]);
        $latest = Thread::factory()->create([
            'organization_id' => $org->id,
            'is_read' => false,
            'message_count' => 3,
            'last_message_at' => now(),
        ]);
        Thread::factory()->create([
            'organization_id' => $other->id,
            'is_read' => false,
            'last_message_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->getJson(route('inbox.sync'))
            ->assertOk()
            ->assertJson([
                'unread' => 2,
                'cursor' => sprintf(
                    '%d:%d:%d',
                    $latest->last_message_at->getTimestamp(),
                    $latest->id,
                    3,
                ),
            ]);
    }

    public function test_sync_returns_empty_cursor_when_the_inbox_is_empty(): void
    {
        [$user, $org] = $this->workspace();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->getJson(route('inbox.sync'))
            ->assertOk()
            ->assertJson(['unread' => 0, 'cursor' => '0:0:0']);
    }

    public function test_guests_are_redirected_from_sync(): void
    {
        $this->getJson(route('inbox.sync'))->assertUnauthorized();
    }

    public function test_shared_inbox_unread_prop_matches_workspace_threads(): void
    {
        [$user, $org] = $this->workspace();
        Thread::factory()->count(3)->create([
            'organization_id' => $org->id,
            'is_read' => false,
        ]);
        Thread::factory()->create([
            'organization_id' => $org->id,
            'is_read' => true,
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('inbox'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('inbox_unread', 3));
    }
}
