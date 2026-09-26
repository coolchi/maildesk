<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\EmailUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminEmailUsageTest extends TestCase
{
    use RefreshDatabase;

    private function message(Organization $org, string $direction, string $createdAt): void
    {
        $m = Message::factory()->create(['organization_id' => $org->id, 'direction' => $direction]);
        $m->forceFill(['created_at' => $createdAt])->save();
    }

    public function test_counts_only_recent_outbound_messages_with_one_grouped_query(): void
    {
        $a = Organization::factory()->create(['emails_30d' => 1240000]);
        $b = Organization::factory()->create(['emails_30d' => 555]);
        $this->message($a, 'outbound', now()->subDays(2)->toDateTimeString());
        $this->message($a, 'outbound', now()->subDays(29)->toDateTimeString());
        $this->message($a, 'outbound', now()->subDays(31)->toDateTimeString());
        $this->message($a, 'inbound', now()->subDay()->toDateTimeString());

        DB::enableQueryLog();
        $counts = app(EmailUsage::class)->counts([$a->id, $b->id]);
        $this->assertCount(1, DB::getQueryLog());

        $this->assertSame([$a->id => 2], $counts);
    }

    public function test_admin_lists_show_live_count_not_seeded_constant(): void
    {
        $org = Organization::factory()->create(['emails_30d' => 1240000]);
        $this->message($org, 'outbound', now()->subDay()->toDateTimeString());
        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin)->get(route('admin.accounts'))
            ->assertInertia(fn (Assert $page) => $page->where('accounts.0.emails30d', 1));
        $this->actingAs($admin)->get(route('admin.accounts.show', $org))
            ->assertInertia(fn (Assert $page) => $page->where('account.emails30d', 1));
        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('accounts.0.emails30d', 1));

        // Nothing written back to the column.
        $this->assertSame(1240000, $org->fresh()->emails_30d);
    }
}
