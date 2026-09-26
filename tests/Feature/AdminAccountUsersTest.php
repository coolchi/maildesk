<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminAccountUsersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Organization, 2: User}
     */
    private function scenario(): array
    {
        $admin = User::factory()->platformAdmin()->create();
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['email' => 'owner@acme.test']);
        $org->users()->attach($owner->id, ['role' => 'owner']);

        return [$admin, $org, $owner];
    }

    public function test_lists_users_with_roles(): void
    {
        [$admin, $org, $owner] = $this->scenario();

        $this->actingAs($admin)->getJson(route('admin.accounts.users', $org))
            ->assertOk()
            ->assertJsonPath('users.0.email', 'owner@acme.test')
            ->assertJsonPath('users.0.role', 'owner')
            ->assertJsonPath('users.0.is_last_owner', true);
    }

    public function test_adds_existing_user_by_email(): void
    {
        [$admin, $org] = $this->scenario();
        $existing = User::factory()->create(['email' => 'dev@acme.test']);

        $this->actingAs($admin)->postJson(route('admin.accounts.users.store', $org), ['email' => 'DEV@acme.test', 'role' => 'admin'])
            ->assertCreated()
            ->assertJsonPath('created', false);

        $this->assertDatabaseHas('organization_user', ['organization_id' => $org->id, 'user_id' => $existing->id, 'role' => 'admin']);
    }

    public function test_creates_new_user_with_random_password_and_sends_nothing(): void
    {
        Mail::fake();
        Notification::fake();
        [$admin, $org] = $this->scenario();

        $response = $this->actingAs($admin)->postJson(route('admin.accounts.users.store', $org), [
            'email' => 'new@acme.test', 'name' => 'New Person', 'role' => 'member',
        ])->assertCreated()->assertJsonPath('created', true);

        $user = User::query()->where('email', 'new@acme.test')->firstOrFail();
        $this->assertFalse($user->isPlatformAdmin());
        $this->assertNotEmpty($user->password);
        $this->assertFalse(Hash::check('', $user->password));
        $this->assertStringNotContainsString('password', strtolower(json_encode($response->json('users'))));
        $this->assertDatabaseHas('organization_user', ['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'member']);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_new_user_requires_name_and_duplicate_member_is_refused(): void
    {
        [$admin, $org] = $this->scenario();

        $this->actingAs($admin)->postJson(route('admin.accounts.users.store', $org), ['email' => 'x@acme.test', 'role' => 'member'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $this->actingAs($admin)->postJson(route('admin.accounts.users.store', $org), ['email' => 'owner@acme.test', 'role' => 'member'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_changes_role(): void
    {
        [$admin, $org] = $this->scenario();
        $member = User::factory()->create();
        $org->users()->attach($member->id, ['role' => 'member']);

        $this->actingAs($admin)->putJson(route('admin.accounts.users.update', [$org, $member]), ['role' => 'owner'])->assertOk();
        $this->assertDatabaseHas('organization_user', ['user_id' => $member->id, 'role' => 'owner']);
    }

    public function test_cannot_demote_or_remove_last_owner(): void
    {
        [$admin, $org, $owner] = $this->scenario();

        $this->actingAs($admin)->putJson(route('admin.accounts.users.update', [$org, $owner]), ['role' => 'member'])
            ->assertStatus(422)->assertJsonValidationErrors('user');
        $this->actingAs($admin)->deleteJson(route('admin.accounts.users.destroy', [$org, $owner]))
            ->assertStatus(422)->assertJsonValidationErrors('user');
        $this->assertDatabaseHas('organization_user', ['user_id' => $owner->id, 'role' => 'owner']);
    }

    public function test_removes_member_and_second_owner(): void
    {
        [$admin, $org] = $this->scenario();
        $second = User::factory()->create();
        $org->users()->attach($second->id, ['role' => 'owner']);

        $this->actingAs($admin)->deleteJson(route('admin.accounts.users.destroy', [$org, $second]))->assertOk();
        $this->assertDatabaseMissing('organization_user', ['organization_id' => $org->id, 'user_id' => $second->id]);
    }

    public function test_platform_admins_cannot_be_demoted_or_removed_here(): void
    {
        [$admin, $org] = $this->scenario();
        $otherAdmin = User::factory()->platformAdmin()->create();
        $org->users()->attach($otherAdmin->id, ['role' => 'admin']);

        $this->actingAs($admin)->putJson(route('admin.accounts.users.update', [$org, $otherAdmin]), ['role' => 'member'])
            ->assertStatus(422);
        $this->actingAs($admin)->deleteJson(route('admin.accounts.users.destroy', [$org, $otherAdmin]))
            ->assertStatus(422);
        $this->assertDatabaseHas('organization_user', ['user_id' => $otherAdmin->id, 'role' => 'admin']);
    }

    public function test_non_admin_is_forbidden(): void
    {
        [, $org, $owner] = $this->scenario();

        $this->actingAs($owner)->getJson(route('admin.accounts.users', $org))->assertForbidden();
        $this->actingAs($owner)->postJson(route('admin.accounts.users.store', $org), ['email' => 'a@b.test', 'name' => 'A', 'role' => 'owner'])->assertForbidden();
    }
}
