<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\OrganizationHost;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AccountAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminAccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->platformAdmin()->create(['password' => 'admin-password']);
    }

    public function test_admin_deletes_account_with_typed_subdomain_and_password(): void
    {
        $admin = $this->admin();
        $org = Organization::factory()->create(['subdomain' => 'acme', 'mrr' => 49]);
        $plan = Plan::query()->create(['key' => 'p', 'product' => 'transactional', 'name' => 'Pro', 'price' => 49, 'interval' => 'month', 'features' => []]);
        $sub = Subscription::query()->create(['key' => 'sub_x', 'organization_id' => $org->id, 'plan_id' => $plan->id, 'plan_name' => 'Pro', 'product' => 'transactional', 'status' => 'active', 'price' => 49, 'seats' => 1]);

        $this->actingAs($admin)
            ->delete(route('admin.accounts.destroy', $org), ['confirmation' => 'ACME', 'password' => 'admin-password'])
            ->assertRedirect(route('admin.accounts'));

        $this->assertSoftDeleted('organizations', ['id' => $org->id]);
        $this->assertSame('canceled', $sub->fresh()->status);
        $this->assertSame(0, Organization::withTrashed()->find($org->id)->mrr);
    }

    public function test_delete_requires_matching_confirmation(): void
    {
        $admin = $this->admin();
        $org = Organization::factory()->create(['subdomain' => 'acme']);

        $this->actingAs($admin)
            ->delete(route('admin.accounts.destroy', $org), ['confirmation' => 'wrong', 'password' => 'admin-password'])
            ->assertSessionHasErrors('confirmation');

        $this->assertNotSoftDeleted('organizations', ['id' => $org->id]);
    }

    public function test_delete_requires_correct_admin_password(): void
    {
        $admin = $this->admin();
        $org = Organization::factory()->create(['subdomain' => 'acme']);

        $this->actingAs($admin)
            ->delete(route('admin.accounts.destroy', $org), ['confirmation' => 'acme', 'password' => 'nope'])
            ->assertSessionHasErrors('password');

        $this->assertNotSoftDeleted('organizations', ['id' => $org->id]);
    }

    public function test_non_admin_cannot_delete_accounts(): void
    {
        $user = User::factory()->create(['password' => 'pw']);
        $org = Organization::factory()->create(['subdomain' => 'acme']);
        $org->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->delete(route('admin.accounts.destroy', $org), ['confirmation' => 'acme', 'password' => 'pw'])
            ->assertForbidden();

        $this->assertNotSoftDeleted('organizations', ['id' => $org->id]);
    }

    public function test_deleted_account_disappears_from_admin_lists_and_show_404s(): void
    {
        $admin = $this->admin();
        $kept = Organization::factory()->create(['name' => 'Kept Co']);
        $gone = Organization::factory()->create(['name' => 'Gone Co']);
        OrganizationHost::query()->create(['organization_id' => $gone->id, 'host' => 'gone.maildesk.test', 'subdomain' => 'gone', 'status' => 'active', 'ssl' => true, 'is_custom' => false]);
        $gone->delete();

        $this->actingAs($admin)->get(route('admin.accounts'))
            ->assertInertia(fn (Assert $page) => $page->has('accounts', 1)->where('accounts.0.name', 'Kept Co'));
        $this->actingAs($admin)->get(route('admin.subdomains'))
            ->assertInertia(fn (Assert $page) => $page->has('hosts', 0));
        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('stats.accounts', 1));
        $this->actingAs($admin)->get(route('admin.accounts.show', $gone->id))->assertNotFound();
    }

    public function test_members_of_deleted_account_cannot_log_in_or_send(): void
    {
        $user = User::factory()->create(['password' => 'pw-12345']);
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $plain = 'md_'.Str::random(40);
        ApiKey::factory()->create(['organization_id' => $org->id, 'key_prefix' => substr($plain, 0, 12), 'key_hash' => hash('sha256', $plain)]);
        $org->delete();

        $this->post('/login', ['email' => $user->email, 'password' => 'pw-12345'])
            ->assertSessionHasErrors(['email' => AccountAccess::CLOSED_MESSAGE]);
        $this->assertGuest();

        $this->withToken($plain)->postJson('/api/v1/emails', ['from' => 'a@b.test', 'to' => 'c@d.test', 'subject' => 'x', 'text' => 'y'])
            ->assertStatus(403)
            ->assertExactJson(['message' => AccountAccess::CLOSED_MESSAGE]);
    }
}
