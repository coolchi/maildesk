<?php

namespace Tests\Feature;

use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminPlansTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->platformAdmin()->create();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'key' => 'tx_growth',
            'product' => 'transactional',
            'name' => 'Growth',
            'price' => 30,
            'price_ngn' => '7500.50',
            'interval' => 'month',
            'emails' => 100000,
            'contacts' => null,
            'seats' => 5,
        ], $overrides);
    }

    public function test_create_plan_with_naira_price_limits_and_interval(): void
    {
        $this->actingAs($this->admin())->post(route('admin.plans.store'), $this->payload(['interval' => 'year']))
            ->assertSessionHasNoErrors()->assertRedirect();

        $plan = Plan::query()->where('key', 'tx_growth')->sole();
        $this->assertSame(750050, $plan->price_kobo);
        $this->assertSame('year', $plan->interval);
        $this->assertSame(100000, $plan->emails);
        $this->assertSame(5, $plan->seats);
    }

    public function test_create_without_naira_price_stores_null(): void
    {
        $this->actingAs($this->admin())->post(route('admin.plans.store'), $this->payload(['price_ngn' => null]))
            ->assertSessionHasNoErrors();

        $this->assertNull(Plan::query()->where('key', 'tx_growth')->sole()->price_kobo);
    }

    public function test_paid_plan_naira_price_must_be_at_least_minimum(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.plans.store'), $this->payload(['price_ngn' => '49.99']))
            ->assertSessionHasErrors('price_ngn');
        $this->assertDatabaseMissing('plans', ['key' => 'tx_growth']);

        // Exactly ₦50 is fine; free plans may carry any naira value.
        $this->actingAs($admin)->post(route('admin.plans.store'), $this->payload(['price_ngn' => '50']))
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.plans.store'), $this->payload(['key' => 'free', 'price' => 0, 'price_ngn' => '10']))
            ->assertSessionHasNoErrors();

        $plan = Plan::factory()->create(['price' => 20, 'price_kobo' => 500000]);
        $this->actingAs($admin)->put(route('admin.plans.update', $plan), ['name' => 'Pro', 'price' => 20, 'price_ngn' => '20'])
            ->assertSessionHasErrors('price_ngn');
        $this->assertSame(500000, $plan->fresh()->price_kobo);
    }

    public function test_minimum_comes_from_monipay_config(): void
    {
        config(['services.monipay.min_amount' => 10000]);

        $this->actingAs($this->admin())->post(route('admin.plans.store'), $this->payload(['price_ngn' => '75']))
            ->assertSessionHasErrors('price_ngn');
    }

    public function test_update_edits_naira_price_limits_and_interval(): void
    {
        $plan = Plan::factory()->create(['price' => 20, 'price_kobo' => null, 'emails' => 50000, 'seats' => 10]);

        $this->actingAs($this->admin())->put(route('admin.plans.update', $plan), [
            'name' => 'Pro', 'price' => 20, 'price_ngn' => '12000', 'interval' => 'year',
            'emails' => 75000, 'contacts' => 2000, 'seats' => null,
        ])->assertSessionHasNoErrors();

        $plan->refresh();
        $this->assertSame(1200000, $plan->price_kobo);
        $this->assertSame('year', $plan->interval);
        $this->assertSame(75000, $plan->emails);
        $this->assertSame(2000, $plan->contacts);
        $this->assertNull($plan->seats);

        // Clearing the naira price.
        $this->actingAs($this->admin())->put(route('admin.plans.update', $plan), ['name' => 'Pro', 'price' => 20, 'price_ngn' => null])
            ->assertSessionHasNoErrors();
        $this->assertNull($plan->fresh()->price_kobo);
    }

    public function test_plans_page_flags_unpayable_plans_and_has_no_mock_fallback(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.plans'))
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Plans/Index')->has('plans', 0)->where('monipayMinKobo', 5000));

        Plan::factory()->create(['key' => 'a_ok', 'price' => 20, 'price_kobo' => 500000]);
        Plan::factory()->create(['key' => 'b_none', 'price' => 20, 'price_kobo' => null]);
        Plan::factory()->create(['key' => 'c_low', 'price' => 20, 'price_kobo' => 4999]);
        Plan::factory()->create(['key' => 'd_free', 'price' => 0, 'price_kobo' => null]);

        $this->actingAs($admin)->get(route('admin.plans'))
            ->assertInertia(fn (Assert $page) => $page->has('plans', 4)
                ->where('plans', fn ($plans) => collect($plans)->pluck('monipay_payable', 'id')->all() == [
                    'a_ok' => true, 'b_none' => false, 'c_low' => false, 'd_free' => true,
                ]));

        $vue = file_get_contents(resource_path('js/Pages/Admin/Plans/Index.vue'));
        $this->assertStringNotContainsString('adminMock', $vue);
    }

    public function test_delete_unused_plan_and_refuse_plan_in_use(): void
    {
        $admin = $this->admin();
        $unused = Plan::factory()->create();
        $used = Plan::factory()->create();
        $org = Organization::factory()->create();
        Subscription::query()->create(['key' => 'sub_1', 'organization_id' => $org->id, 'plan_id' => $used->id, 'plan_name' => 'Pro', 'product' => 'transactional', 'status' => 'canceled', 'price' => 20, 'seats' => 1]);

        $this->actingAs($admin)->delete(route('admin.plans.destroy', $unused))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('plans', ['id' => $unused->id]);

        $this->actingAs($admin)->delete(route('admin.plans.destroy', $used))->assertSessionHasErrors('plan');
        $this->assertDatabaseHas('plans', ['id' => $used->id]);
    }

    public function test_non_admin_cannot_manage_plans(): void
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create();

        $this->actingAs($user)->post(route('admin.plans.store'), $this->payload())->assertForbidden();
        $this->actingAs($user)->delete(route('admin.plans.destroy', $plan))->assertForbidden();
    }

    public function test_checkout_charges_the_admin_set_naira_price_exactly(): void
    {
        config([
            'services.monipay.public_key' => 'pub_test_x',
            'services.monipay.secret_key' => 'pri_test_never_leak',
            'services.monipay.naira_per_price_unit' => 1500,
        ]);
        $plan = Plan::factory()->create(['key' => 'tx_pro', 'price' => 20, 'price_kobo' => null]);
        $this->actingAs($this->admin())->put(route('admin.plans.update', $plan), ['name' => 'Pro', 'price' => 20, 'price_ngn' => '7500.50'])
            ->assertSessionHasNoErrors();

        $user = User::factory()->create();
        $provider = MailProvider::factory()->create(['key' => 'resend_x', 'driver' => 'resend', 'status' => 'active']);
        $org = Organization::factory()->create(['mail_provider_id' => $provider->id]);
        $org->users()->attach($user->id, ['role' => 'owner']);

        Http::fake(['api.monipay.ng/transaction/initialize' => Http::response([
            'status' => true, 'data' => ['authorization_url' => 'https://checkout.monipay.ng/pay/x', 'access_code' => 'ac', 'reference' => 'r'],
        ])]);

        // Billing tab shows the naira amount.
        $this->actingAs($user)->withSession(['current_organization_id' => $org->id])->get('/settings/billing')
            ->assertInertia(fn (Assert $page) => $page
                ->where('payments.plans.0.amount', 750050)
                ->where('payments.plans.0.amount_formatted', '₦7,500.50'));

        $this->actingAs($user)->withSession(['current_organization_id' => $org->id])
            ->post(route('billing.monipay.initialize'), ['plan' => 'tx_pro'])
            ->assertRedirect('https://checkout.monipay.ng/pay/x');

        $this->assertSame(750050, Payment::query()->sole()->amount);
        Http::assertSent(fn (HttpRequest $r) => $r['amount'] === 750050);
    }
}
