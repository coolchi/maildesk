<?php

namespace Tests\Feature;

use App\Exceptions\AccountSuspendedException;
use App\Models\ApiKey;
use App\Models\Broadcast;
use App\Models\Domain;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccountAccess;
use App\Services\BroadcastService;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountSuspensionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Organization}
     */
    private function member(string $status = 'active'): array
    {
        $user = User::factory()->create(['password' => 'secret-password']);
        $org = Organization::factory()->create(['status' => $status, 'default_provider' => 'array']);
        $org->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $org];
    }

    private function apiKeyFor(Organization $org): string
    {
        $plain = 'md_'.Str::random(40);
        ApiKey::factory()->create([
            'organization_id' => $org->id,
            'key_prefix' => substr($plain, 0, 12),
            'key_hash' => hash('sha256', $plain),
        ]);

        return $plain;
    }

    public function test_suspended_user_cannot_log_in(): void
    {
        [$user] = $this->member('suspended');

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertSessionHasErrors(['email' => AccountAccess::SUSPENDED_MESSAGE]);

        $this->assertGuest();
    }

    public function test_active_user_can_still_log_in(): void
    {
        [$user] = $this->member();

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_existing_session_is_logged_out_when_account_is_suspended(): void
    {
        [$user, $org] = $this->member();
        $org->update(['status' => 'suspended']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get('/emails')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => AccountAccess::SUSPENDED_MESSAGE]);

        $this->assertGuest();
    }

    public function test_user_with_another_active_workspace_is_moved_off_the_suspended_one(): void
    {
        [$user, $suspended] = $this->member('suspended');
        $active = Organization::factory()->create(['status' => 'active']);
        $active->users()->attach($user->id, ['role' => 'member']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $suspended->id])
            ->get('/emails')
            ->assertOk();

        $this->assertAuthenticatedAs($user);
        $this->assertSame($active->id, session('current_organization_id'));
    }

    public function test_platform_admin_is_never_locked_out(): void
    {
        $admin = User::factory()->platformAdmin()->create(['password' => 'secret-password']);
        $org = Organization::factory()->create(['status' => 'suspended']);
        $org->users()->attach($admin->id, ['role' => 'owner']);

        $this->post('/login', ['email' => $admin->email, 'password' => 'secret-password'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($admin);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('admin.accounts.show', $org))->assertOk();
    }

    public function test_api_send_returns_403_for_suspended_account(): void
    {
        [, $org] = $this->member('suspended');
        $key = $this->apiKeyFor($org);

        $this->withToken($key)
            ->postJson('/api/v1/emails', [
                'from' => 'hello@acme.test',
                'to' => 'someone@example.com',
                'subject' => 'Hi',
                'text' => 'Hello',
            ])
            ->assertStatus(403)
            ->assertExactJson(['message' => AccountAccess::SUSPENDED_MESSAGE]);

        $this->assertSame(0, Message::query()->count());
    }

    public function test_api_send_still_works_for_active_account(): void
    {
        [, $org] = $this->member();
        Domain::factory()->verified()->create(['organization_id' => $org->id, 'name' => 'acme.test']);
        $key = $this->apiKeyFor($org);

        $this->withToken($key)
            ->postJson('/api/v1/emails', [
                'from' => 'hello@acme.test',
                'to' => 'someone@example.com',
                'subject' => 'Hi',
                'text' => 'Hello',
            ])
            ->assertStatus(201);
    }

    public function test_email_service_refuses_suspended_account_centrally(): void
    {
        [, $org] = $this->member('suspended');

        $this->expectException(AccountSuspendedException::class);

        try {
            app(EmailService::class)->send($org, [
                'from' => 'hello@acme.test',
                'to' => 'someone@example.com',
                'subject' => 'Hi',
                'text' => 'Hello',
            ]);
        } finally {
            $this->assertSame(0, Message::query()->count());
        }
    }

    public function test_scheduled_delivery_of_suspended_account_is_failed_not_sent(): void
    {
        [, $org] = $this->member();
        $message = app(EmailService::class)->send($org, [
            'from' => 'hello@acme.test',
            'to' => 'someone@example.com',
            'subject' => 'Later',
            'text' => 'Hello',
            'scheduled_at' => now()->addHour(),
        ]);
        $this->assertSame('scheduled', $message->status);

        $org->update(['status' => 'suspended']);
        $result = app(EmailService::class)->deliver($org->fresh(), $message);

        $this->assertSame('failed', $result->status);
        $this->assertSame(AccountAccess::SUSPENDED_MESSAGE, $result->meta['error']);
        $this->assertNull($result->sent_at);
    }

    public function test_compose_by_admin_in_suspended_workspace_is_refused(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $provider = MailProvider::factory()->create(['key' => 'resend', 'driver' => 'resend', 'status' => 'active']);
        $org = Organization::factory()->create([
            'status' => 'suspended',
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
        ]);
        Domain::factory()->verified()->create(['organization_id' => $org->id, 'name' => 'acme.test']);

        $this->actingAs($admin)
            ->withSession(['current_organization_id' => $org->id])
            ->from('/compose')
            ->post(route('emails.store'), [
                'from' => 'hello@acme.test',
                'to' => 'customer@example.com',
                'subject' => 'Nope',
                'html' => '<p>Nope</p>',
            ])
            ->assertRedirect('/compose')
            ->assertSessionHas('error', AccountAccess::SUSPENDED_MESSAGE);

        $this->assertSame(0, Message::query()->count());
    }

    public function test_broadcast_queue_is_refused_for_suspended_account(): void
    {
        [, $org] = $this->member('suspended');
        $broadcast = Broadcast::query()->create([
            'organization_id' => $org->id,
            'name' => 'Launch',
            'subject' => 'We launched',
            'html' => '<p>Hi</p>',
            'status' => 'draft',
        ]);

        $this->expectException(AccountSuspendedException::class);
        app(BroadcastService::class)->queue($broadcast, 'all', 'news@acme.test');
    }

    public function test_admin_cannot_start_impersonating_a_suspended_account(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        [$target, $org] = $this->member('suspended');

        $this->actingAs($admin)
            ->from(route('admin.accounts.show', $org))
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.impersonate', $target), [
                'organization_id' => $org->id,
                'reason' => 'Investigating support ticket #1',
            ])
            ->assertRedirect(route('admin.accounts.show', $org))
            ->assertSessionHasErrors('user');

        $this->assertSame($admin->id, Auth::id());
    }

    public function test_open_impersonation_ends_when_account_is_suspended(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        [$target, $org] = $this->member();

        $this->actingAs($admin)
            ->from(route('admin.accounts.show', $org))
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.impersonate', $target), [
                'organization_id' => $org->id,
                'reason' => 'Investigating support ticket #1',
            ]);
        $this->assertSame($target->id, Auth::id());

        $org->update(['status' => 'suspended']);

        $this->get('/emails')->assertRedirect();

        $this->assertSame($admin->id, Auth::id());
        $this->assertStringContainsString('Impersonation ended', (string) session('error'));
    }
}
