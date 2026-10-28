<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class MobileAuthTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->org = Organization::factory()->create();
        $this->org->users()->attach($this->user->id, ['role' => 'owner']);
    }

    public function test_login_returns_token_and_user_data(): void
    {
        $response = $this->postJson('/api/v1/mobile/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
            'device_name' => 'iPhone 15',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email', 'is_platform_admin'],
                'workspaces',
            ]);

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame($this->user->id, $response->json('user.id'));
        $this->assertCount(1, $response->json('workspaces'));
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $response = $this->postJson('/api/v1/mobile/auth/login', [
            'email' => $this->user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_login_fails_for_suspended_account(): void
    {
        $this->org->update(['status' => 'suspended']);

        $response = $this->postJson('/api/v1/mobile/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_login_fails_for_inactive_mailbox_user(): void
    {
        Mailbox::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'status' => 'pending',
        ]);

        $response = $this->postJson('/api/v1/mobile/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_logout_revokes_token(): void
    {
        $token = $this->user->createToken('Test')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/v1/mobile/auth/logout');

        $response->assertOk()
            ->assertJson(['message' => 'Logged out.']);

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_me_returns_user_and_workspaces(): void
    {
        $token = $this->user->createToken('Test')->plainTextToken;

        $anotherOrg = Organization::factory()->create();
        $this->user->organizations()->attach($anotherOrg->id, ['role' => 'admin']);

        $response = $this->withToken($token)
            ->getJson('/api/v1/mobile/auth/me');

        $response->assertOk()
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email', 'is_platform_admin'],
                'workspaces',
            ]);

        $this->assertCount(2, $response->json('workspaces'));
    }

    public function test_protected_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/mobile/auth/me')->assertStatus(401);
        $this->postJson('/api/v1/mobile/auth/logout')->assertStatus(401);
        $this->getJson('/api/v1/mobile/inbox')->assertStatus(401);
    }

    public function test_login_rate_limiting(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/mobile/auth/login', [
                'email' => $this->user->email,
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->postJson('/api/v1/mobile/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $errors = $response->json('errors.email');
        $this->assertTrue(
            collect($errors)->contains(fn ($e) => str_contains($e, 'Too many')),
            'Rate limiting message should mention too many attempts',
        );
    }

    public function test_token_has_expiration_date(): void
    {
        $response = $this->postJson('/api/v1/mobile/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
            'device_name' => 'Test Device',
        ]);

        $response->assertOk();

        $token = PersonalAccessToken::query()->latest()->first();

        $this->assertNotNull($token->expires_at);
        $this->assertTrue(
            $token->expires_at->isBetween(now()->addDays(59), now()->addDays(61)),
            'Token should expire in approximately 60 days',
        );
    }

    public function test_expired_token_is_rejected(): void
    {
        $token = $this->user->createToken('Test', ['*'], now()->subDay())->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/v1/mobile/auth/me');

        $response->assertStatus(401);
    }

    public function test_password_change_revokes_all_tokens(): void
    {
        $token1 = $this->user->createToken('iPhone')->plainTextToken;
        $token2 = $this->user->createToken('iPad')->plainTextToken;
        $token3 = $this->user->createToken('Android')->plainTextToken;

        $this->assertSame(3, $this->user->tokens()->count());

        $this->actingAs($this->user)
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ]);

        $this->assertSame(0, $this->user->fresh()->tokens()->count());

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_membership_revocation_blocks_workspace_access(): void
    {
        $token = $this->user->createToken('Test')->plainTextToken;

        $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => $this->org->id])
            ->getJson('/api/v1/mobile/inbox')
            ->assertOk();

        $this->org->users()->detach($this->user->id);

        $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => $this->org->id])
            ->getJson('/api/v1/mobile/inbox')
            ->assertStatus(403);
    }

    public function test_token_still_works_for_other_workspaces_after_one_revocation(): void
    {
        $otherOrg = Organization::factory()->create();
        $this->user->organizations()->attach($otherOrg->id, ['role' => 'member']);

        $token = $this->user->createToken('Test')->plainTextToken;

        $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => $this->org->id])
            ->getJson('/api/v1/mobile/inbox')
            ->assertOk();

        $this->org->users()->detach($this->user->id);

        $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => $this->org->id])
            ->getJson('/api/v1/mobile/inbox')
            ->assertStatus(403);

        $this->withToken($token)
            ->withHeaders(['X-Workspace-Id' => $otherOrg->id])
            ->getJson('/api/v1/mobile/inbox')
            ->assertOk();
    }
}
