<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\RevenueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminRevenueTest extends TestCase
{
    use RefreshDatabase;

    private function subscribe(Organization $org, Plan $plan, string $status = 'active'): Subscription
    {
        return Subscription::query()->create([
            'key' => 'sub_'.Str::random(8), 'organization_id' => $org->id, 'plan_id' => $plan->id,
            'plan_name' => $plan->name, 'product' => $plan->product, 'status' => $status,
            'price' => $plan->price, 'seats' => 1,
        ]);
    }

    private function catalog(): array
    {
        $monthly = Plan::factory()->create(['key' => 'm', 'name' => 'Pro', 'price' => 20, 'interval' => 'month']);
        $yearly = Plan::factory()->create(['key' => 'y', 'name' => 'Pro Yearly', 'price' => 240, 'interval' => 'year']);
        $free = Plan::factory()->create(['key' => 'f', 'name' => 'Free', 'price' => 0]);

        $this->subscribe(Organization::factory()->create(), $monthly);
        $this->subscribe(Organization::factory()->create(), $monthly);
        $this->subscribe(Organization::factory()->create(), $yearly);          // 240/12 = 20
        $this->subscribe(Organization::factory()->create(), $free);            // not paid
        $this->subscribe(Organization::factory()->create(), $monthly, 'past_due'); // not active
        $deleted = Organization::factory()->create();
        $this->subscribe($deleted, $monthly);
        $deleted->delete();                                                    // excluded

        return [$monthly, $yearly];
    }

    public function test_mrr_normalises_yearly_and_counts_only_active_paid_live_subscriptions(): void
    {
        $this->catalog();

        $this->assertEqualsWithDelta(60.0, app(RevenueService::class)->mrr(), 0.001);
    }

    public function test_revenue_page_and_dashboard_use_the_shared_mrr(): void
    {
        $this->catalog();
        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin)->get(route('admin.revenue'))
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Revenue/Index')
                ->where('summary.mrr', fn ($v) => abs($v - 60) < 0.001)
                ->where('summary.paidSubscriptions', 3)
                ->has('breakdown', 2)
                ->where('breakdown', fn ($rows) => collect($rows)->pluck('mrr', 'planKey')->map(fn ($v) => (float) $v)->all() == ['m' => 40.0, 'y' => 20.0]));

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('stats.mrr', fn ($v) => abs($v - 60) < 0.001));
    }

    public function test_lists_fulfilled_payments_and_monthly_totals_read_only(): void
    {
        $org = Organization::factory()->create(['name' => 'Acme']);
        $plan = Plan::factory()->create(['key' => 'tx_pro']);
        $base = ['organization_id' => $org->id, 'plan_id' => $plan->id, 'provider' => 'monipay', 'currency' => 'NGN'];
        Payment::query()->create($base + ['reference' => 'md_1', 'amount' => 500000, 'status' => 'paid', 'paid_at' => '2026-08-10 10:00:00', 'fulfilled_at' => '2026-08-10 10:00:05']);
        Payment::query()->create($base + ['reference' => 'md_2', 'amount' => 250000, 'status' => 'paid', 'paid_at' => '2026-09-02 10:00:00', 'fulfilled_at' => '2026-09-02 10:00:05']);
        Payment::query()->create($base + ['reference' => 'md_3', 'amount' => 100000, 'status' => 'paid', 'paid_at' => '2026-09-03 10:00:00', 'fulfilled_at' => '2026-09-03 10:00:05']);
        Payment::query()->create($base + ['reference' => 'md_4', 'amount' => 999900, 'status' => 'pending']);
        Payment::query()->create($base + ['reference' => 'md_5', 'amount' => 999900, 'status' => 'failed']);

        $before = Payment::query()->orderBy('id')->get()->toArray();

        $this->actingAs(User::factory()->platformAdmin()->create())->get(route('admin.revenue'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentsAvailable', true)
                ->has('payments', 3)
                ->where('monthly.0.month', '2026-09')
                ->where('monthly.0.total', 350000)
                ->where('monthly.0.totalFormatted', '₦3,500.00')
                ->where('monthly.1.month', '2026-08')
                ->where('monthly.1.total', 500000));

        $this->assertSame($before, Payment::query()->orderBy('id')->get()->toArray());
    }

    public function test_plan_price_change_recomputes_account_mrr(): void
    {
        [$monthly] = $this->catalog();
        $org = Organization::factory()->create(['mrr' => 999]);
        $this->subscribe($org, $monthly);

        $this->actingAs(User::factory()->platformAdmin()->create())
            ->put(route('admin.plans.update', $monthly), ['name' => 'Pro', 'price' => 35])
            ->assertSessionHasNoErrors();

        $this->assertSame(35, $org->fresh()->mrr);

        // Switching to yearly billing normalises the account MRR too.
        $this->actingAs(User::factory()->platformAdmin()->create())
            ->put(route('admin.plans.update', $monthly), ['name' => 'Pro', 'price' => 360, 'interval' => 'year'])
            ->assertSessionHasNoErrors();
        $this->assertSame(30, $org->fresh()->mrr);
    }

    public function test_non_admin_cannot_view_revenue(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.revenue'))->assertForbidden();
    }
}
