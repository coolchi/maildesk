<?php

namespace Tests\Feature;

use App\Http\Middleware\ImpersonationGuard;
use App\Models\Domain;
use App\Models\ImpersonationAction;
use App\Models\ImpersonationLog;
use App\Models\Mailbox;
use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Investigating support ticket #4521';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'maildesk.base_domain' => 'maildesk.test',
            'maildesk.central_domains' => ['maildesk.test'],
            'app.url' => 'http://maildesk.test',
            'session.domain' => '.maildesk.test',
            'impersonation.ttl_minutes' => 30,
        ]);
    }

    /**
     * @return array{0: User, 1: User, 2: Organization}
     */
    private function scenario(): array
    {
        $admin = User::factory()->platformAdmin()->create(['name' => 'Ada Admin']);
        $target = User::factory()->create(['name' => 'Tom Owner', 'email' => 'tom@acme.test']);
        $org = Organization::factory()->create(['subdomain' => 'acme']);
        $org->users()->attach($target->id, ['role' => 'owner']);

        return [$admin, $target, $org];
    }

    private function start(User $admin, User $target, Organization $org, array $overrides = [], bool $confirmed = true)
    {
        $request = $this->actingAs($admin)
            ->from(route('admin.accounts.show', $org));

        if ($confirmed) {
            $request = $request->withSession(['auth.password_confirmed_at' => time()]);
        }

        return $request->post(route('admin.impersonate', $target), array_merge([
            'organization_id' => $org->id,
            'reason' => self::REASON,
        ], $overrides));
    }

    private function log(): ImpersonationLog
    {
        return ImpersonationLog::query()->latest('id')->firstOrFail();
    }

    public function test_admin_can_start_impersonation_after_confirming_password(): void
    {
        [$admin, $target, $org] = $this->scenario();

        // Establish a session id, then send it back as the cookie so we can
        // prove the id is rotated on start.
        $this->actingAs($admin)->get(route('admin.accounts.show', $org))->assertOk();
        $oldId = session()->getId();
        $oldToken = session()->token();

        $response = $this->withCookie(config('session.cookie'), $oldId)
            ->withHeader('User-Agent', 'PHPUnit Browser')
            ->start($admin, $target, $org);

        $response->assertRedirect('http://acme.maildesk.test/inbox');
        $response->assertCookieMissing(Auth::guard('web')->getRecallerName());
        $this->assertSame($target->id, Auth::id());
        $this->assertNotSame($oldId, session()->getId());
        $this->assertNotSame($oldToken, session()->token());
        $this->assertSame($admin->id, session('impersonator_id'));
        $this->assertSame($org->id, session('current_organization_id'));
        $this->assertNull(session('auth.password_confirmed_at'));

        $log = $this->log();
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame($target->id, $log->user_id);
        $this->assertSame($org->id, $log->organization_id);
        $this->assertSame(self::REASON, $log->reason);
        $this->assertSame('127.0.0.1', $log->ip);
        $this->assertSame('PHPUnit Browser', $log->user_agent);
        $this->assertNull($log->ended_at);
        $this->assertSame(session('impersonation_log_id'), $log->id);
    }

    public function test_inertia_start_uses_location_visit(): void
    {
        [$admin, $target, $org] = $this->scenario();

        $this->withHeader('X-Inertia', 'true')
            ->start($admin, $target, $org)
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'http://acme.maildesk.test/inbox');
    }

    public function test_reason_is_required_and_bounded(): void
    {
        [$admin, $target, $org] = $this->scenario();

        $this->start($admin, $target, $org, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->start($admin, $target, $org, ['reason' => 'too short'])->assertSessionHasErrors('reason');
        $this->start($admin, $target, $org, ['reason' => str_repeat('a', 501)])->assertSessionHasErrors('reason');

        $this->assertSame(0, ImpersonationLog::query()->count());
        $this->assertSame($admin->id, Auth::id());
    }

    public function test_password_confirmation_is_required_and_returns_to_account_page(): void
    {
        [$admin, $target, $org] = $this->scenario();
        $accountUrl = route('admin.accounts.show', $org);

        $this->start($admin, $target, $org, confirmed: false)
            ->assertRedirect(route('password.confirm'));
        $this->assertSame($accountUrl, session('url.intended'));
        $this->assertSame(0, ImpersonationLog::query()->count());

        // A stale (older than 10 minutes) confirmation is not enough either.
        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time() - 601])
            ->from($accountUrl)
            ->post(route('admin.impersonate', $target), ['organization_id' => $org->id, 'reason' => self::REASON])
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($admin)
            ->post(route('password.confirm'), ['password' => 'password'])
            ->assertRedirect($accountUrl);

        $this->actingAs($admin)
            ->from($accountUrl)
            ->post(route('admin.impersonate', $target), ['organization_id' => $org->id, 'reason' => self::REASON])
            ->assertRedirect('http://acme.maildesk.test/inbox');
    }

    public function test_get_is_not_allowed(): void
    {
        [$admin, $target] = $this->scenario();

        $this->actingAs($admin)
            ->get('/admin/users/'.$target->id.'/impersonate')
            ->assertStatus(405);
    }

    public function test_non_admin_cannot_impersonate(): void
    {
        [, $target, $org] = $this->scenario();
        $member = User::factory()->create();
        $org->users()->attach($member->id, ['role' => 'admin']);

        $this->start($member, $target, $org)->assertForbidden();
        $this->assertSame(0, ImpersonationLog::query()->count());
    }

    public function test_cannot_impersonate_platform_admin_or_self(): void
    {
        [$admin, , $org] = $this->scenario();
        $otherAdmin = User::factory()->platformAdmin()->create();
        $org->users()->attach($otherAdmin->id, ['role' => 'member']);
        $org->users()->attach($admin->id, ['role' => 'member']);

        $this->start($admin, $otherAdmin, $org)->assertSessionHasErrors('user');
        $this->start($admin, $admin, $org)->assertSessionHasErrors('user');

        // Refused attempts are audited as closed "denied" logs, never opened.
        $this->assertSame(0, ImpersonationLog::query()->where('end_reason', '!=', 'denied')->count());
        $this->assertSame(2, ImpersonationLog::query()->where('end_reason', 'denied')->whereNotNull('ended_at')->count());
        $this->assertSame($admin->id, Auth::id());
    }

    public function test_target_must_belong_to_the_account(): void
    {
        [$admin, $target] = $this->scenario();
        $otherOrg = Organization::factory()->create();
        $loner = User::factory()->create();

        $this->start($admin, $target, $otherOrg)->assertSessionHasErrors('user');
        $this->start($admin, $loner, $otherOrg)->assertSessionHasErrors('user');
        $this->assertSame(0, ImpersonationLog::query()->where('end_reason', '!=', 'denied')->count());
        $this->assertSame(2, ImpersonationLog::query()->where('end_reason', 'denied')->count());
    }

    public function test_cannot_nest_impersonation(): void
    {
        [$admin, $target, $org] = $this->scenario();
        $second = User::factory()->create();
        $org->users()->attach($second->id, ['role' => 'member']);

        $this->start($admin, $target, $org);

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.impersonate', $second), ['organization_id' => $org->id, 'reason' => self::REASON])
            ->assertForbidden();

        $this->assertSame(1, ImpersonationLog::query()->count());
        $this->assertSame($target->id, Auth::id());
    }

    public function test_admin_area_is_blocked_while_impersonating(): void
    {
        [$admin, $target, $org] = $this->scenario();
        $this->start($admin, $target, $org);

        $this->get(route('admin.dashboard'))->assertForbidden();
        $this->get(route('admin.accounts.show', $org))->assertForbidden();

        $this->assertSame(2, ImpersonationAction::query()->where('blocked', true)->count());
    }

    public function test_mutations_are_blocked_and_audited(): void
    {
        [$admin, $target, $org] = $this->scenario();
        $webhook = Webhook::factory()->create(['organization_id' => $org->id]);
        $domain = Domain::factory()->create(['organization_id' => $org->id]);
        $originalName = $target->name;

        $this->start($admin, $target, $org);

        $attempts = [
            ['post', route('emails.store'), ['from' => 'a@acme.test', 'to' => 'b@example.com', 'subject' => 'Hi', 'text' => 'x']],
            ['post', route('inbox.reply', 1), ['text' => 'hello']],
            ['post', route('api-keys.store'), ['name' => 'Key']],
            ['put', route('settings.smtp.update'), ['host' => 'smtp.example.com']],
            ['put', route('settings.update'), ['documents' => []]],
            ['patch', route('profile.update'), ['name' => 'Hacked', 'email' => $target->email]],
            ['put', route('password.update'), ['current_password' => 'password', 'password' => 'new-pass-123', 'password_confirmation' => 'new-pass-123']],
            ['post', route('webhooks.store'), ['url' => 'https://evil.example/hook', 'events' => ['email.delivered']]],
            ['delete', route('webhooks.destroy', $webhook), []],
            ['post', route('domains.dns.connect', $domain), ['token' => 'x']],
            ['post', route('workspace.switch', $org), []],
            ['patch', route('inbox.read', 1), ['read' => true]],
        ];

        foreach ($attempts as [$method, $url, $data]) {
            $this->{$method}($url, $data)->assertForbidden();
        }

        $this->assertSame($target->id, Auth::id());
        $this->assertSame($originalName, $target->fresh()->name);
        $this->assertDatabaseCount('api_keys', 0);
        $this->assertDatabaseHas('webhooks', ['id' => $webhook->id]);
        $this->assertDatabaseMissing('webhooks', ['url' => 'https://evil.example/hook']);
        $this->assertDatabaseCount('messages', 0);

        $log = $this->log();
        $this->assertSame(count($attempts), $log->actions()->where('blocked', true)->count());
        $this->assertDatabaseHas('impersonation_actions', [
            'impersonation_log_id' => $log->id,
            'method' => 'POST',
            'route_name' => 'emails.store',
            'path' => '/emails',
            'status' => 403,
            'blocked' => true,
        ]);
    }

    public function test_inertia_mutation_is_bounced_back_with_flash_error(): void
    {
        [$admin, $target, $org] = $this->scenario();
        $this->start($admin, $target, $org);

        $this->withHeader('X-Inertia', 'true')
            ->from('/webhooks')
            ->post(route('webhooks.store'), ['url' => 'https://evil.example/hook', 'events' => ['email.delivered']])
            ->assertStatus(303)
            ->assertRedirect('/webhooks')
            ->assertSessionHas('error', ImpersonationGuard::READ_ONLY_MESSAGE);

        $this->assertDatabaseCount('webhooks', 0);
        $this->assertTrue(ImpersonationAction::query()->where('route_name', 'webhooks.store')->value('blocked'));
    }

    public function test_sensitive_get_routes_are_denied(): void
    {
        [$admin, $target, $org] = $this->scenario();
        $this->start($admin, $target, $org);

        $this->get(route('profile.edit'))->assertForbidden();
        $this->get(route('password.confirm'))->assertForbidden();
        $this->get(route('billing.monipay.callback'))->assertForbidden();
    }

    public function test_read_only_pages_are_allowed_and_audited(): void
    {
        [$admin, $target, $org] = $this->scenario();
        $this->start($admin, $target, $org);

        $this->get(route('emails'))->assertOk();
        $this->get(route('webhooks'))->assertOk();
        $this->get('/settings/usage')->assertOk();

        $log = $this->log();
        $this->assertSame(3, $log->actions()->where('blocked', false)->count());
        $this->assertDatabaseHas('impersonation_actions', [
            'impersonation_log_id' => $log->id,
            'method' => 'GET',
            'route_name' => 'emails',
            'path' => '/emails',
            'status' => 200,
            'blocked' => false,
        ]);
    }

    public function test_webhook_secret_is_not_revealed_while_impersonating(): void
    {
        [$admin, $target, $org] = $this->scenario();
        $webhook = Webhook::factory()->create(['organization_id' => $org->id, 'secret' => 'whsec_supersecretvalue123456']);

        // Owner themselves still sees it.
        $this->actingAs($target)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('webhooks.show', $webhook))
            ->assertOk()
            ->assertSee('whsec_supersecretvalue123456', false);

        $this->start($admin, $target, $org);

        $response = $this->get(route('webhooks.show', $webhook))->assertOk();
        $response->assertDontSee('whsec_supersecretvalue123456', false);
        $response->assertInertia(fn ($page) => $page->missing('webhook.secret')->etc());
    }

    public function test_viewing_thread_does_not_mark_it_read(): void
    {
        [$admin, $target, $org] = $this->scenario();
        $thread = Thread::factory()->create(['organization_id' => $org->id, 'is_read' => false]);

        $this->start($admin, $target, $org);
        $this->get(route('inbox.show', $thread->id))->assertOk();

        $this->assertFalse((bool) $thread->fresh()->is_read);
    }

    public function test_shared_impersonation_prop(): void
    {
        [$admin, $target, $org] = $this->scenario();

        $this->actingAs($target)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('emails'))
            ->assertInertia(fn ($page) => $page->where('impersonation', null)->etc());

        $this->freezeSecond();
        $this->start($admin, $target, $org);

        $this->get(route('emails'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('impersonation.active', true)
                ->where('impersonation.user.name', 'Tom Owner')
                ->where('impersonation.user.email', 'tom@acme.test')
                ->where('impersonation.impersonator.name', 'Ada Admin')
                ->where('impersonation.return_label', 'Return to admin')
                ->where('impersonation.expires_at', now()->addMinutes(30)->toIso8601String())
                ->etc());
    }

    public function test_leave_restores_admin_and_closes_log(): void
    {
        [$admin, $target, $org] = $this->scenario();
        $this->start($admin, $target, $org);

        $this->post(route('impersonate.leave'))
            ->assertRedirect('http://maildesk.test/admin/accounts/'.$org->id);

        $this->assertSame($admin->id, Auth::id());
        $this->assertNull(session('impersonator_id'));
        $this->assertNull(session('impersonation_log_id'));
        $this->assertNull(session('current_organization_id'));

        $log = $this->log();
        $this->assertNotNull($log->ended_at);
        $this->assertSame('left', $log->end_reason);
        $this->assertTrue($log->actions()->where('route_name', 'impersonate.leave')->where('blocked', false)->exists());

        // Back to normal admin access; must re-confirm password to start again.
        $this->get(route('admin.dashboard'))->assertOk();
        $this->from(route('admin.accounts.show', $org))
            ->post(route('admin.impersonate', $target), ['organization_id' => $org->id, 'reason' => self::REASON])
            ->assertRedirect(route('password.confirm'));
    }

    public function test_leave_via_inertia_uses_location_visit(): void
    {
        [$admin, $target, $org] = $this->scenario();
        $this->start($admin, $target, $org);

        $this->withHeader('X-Inertia', 'true')
            ->post(route('impersonate.leave'))
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'http://maildesk.test/admin/accounts/'.$org->id);
    }

    public function test_logout_while_impersonating_restores_admin_and_keeps_target_remember_token(): void
    {
        [$admin, $target, $org] = $this->scenario();
        $target->forceFill(['remember_token' => 'target-remember-token'])->save();

        $this->start($admin, $target, $org);

        $this->post(route('logout'))
            ->assertRedirect('http://maildesk.test/admin/accounts/'.$org->id);

        $this->assertSame($admin->id, Auth::id());
        $this->assertSame('target-remember-token', $target->fresh()->remember_token);
        $this->assertSame('logout', $this->log()->end_reason);
        $this->assertNotNull($this->log()->ended_at);
    }

    public function test_normal_logout_still_logs_out(): void
    {
        [, $target] = $this->scenario();

        $this->actingAs($target)->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_session_expires_after_ttl(): void
    {
        [$admin, $target, $org] = $this->scenario();
        $this->start($admin, $target, $org);

        $this->travel(29)->minutes();
        $this->get(route('emails'))->assertOk();
        $this->assertSame($target->id, Auth::id());

        $this->travel(2)->minutes();
        $this->get(route('emails'))
            ->assertRedirect('http://maildesk.test/admin/accounts/'.$org->id)
            ->assertSessionHas('error');

        $this->assertSame($admin->id, Auth::id());
        $this->assertNull(session('impersonator_id'));
        $log = $this->log();
        $this->assertSame('expired', $log->end_reason);
        $this->assertNotNull($log->ended_at);
        $this->assertTrue($log->actions()->latest('id')->first()->blocked);
    }

    public function test_revoked_admin_flag_ends_session_and_signs_out(): void
    {
        [$admin, $target, $org] = $this->scenario();
        $target->forceFill(['remember_token' => 'target-remember-token'])->save();
        $this->start($admin, $target, $org);

        $admin->forceFill(['is_platform_admin' => false])->save();

        $this->get(route('emails'))->assertRedirect('http://maildesk.test/login');

        $this->assertGuest();
        $this->assertSame('revoked', $this->log()->end_reason);
        $this->assertSame('target-remember-token', $target->fresh()->remember_token);
    }

    public function test_stale_open_logs_are_closed_on_next_start(): void
    {
        [$admin, $target, $org] = $this->scenario();
        $stale = ImpersonationLog::query()->create([
            'admin_id' => $admin->id,
            'user_id' => $target->id,
            'organization_id' => $org->id,
            'reason' => 'Old session never closed',
            'started_at' => now()->subHours(2),
        ]);

        $this->start($admin, $target, $org);

        $this->assertSame('expired', $stale->fresh()->end_reason);
        $this->assertNull($this->log()->ended_at);
    }

    public function test_account_page_lists_members_for_picker_and_recent_logs(): void
    {
        [$admin, $target, $org] = $this->scenario();
        $otherAdmin = User::factory()->platformAdmin()->create();
        $org->users()->attach($otherAdmin->id, ['role' => 'member']);
        $org->users()->attach($admin->id, ['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.accounts.show', $org))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Accounts/Show')
                ->has('members', 3)
                ->where('members.0.id', $target->id)
                ->where('members.0.role', 'owner')
                ->where('members.0.can_impersonate', true)
                ->where('members.1.id', $admin->id)
                ->where('members.1.can_impersonate', false)
                ->where('members.1.disabled_reason', 'This is you')
                ->where('members.2.is_platform_admin', true)
                ->where('members.2.can_impersonate', false)
                ->has('impersonationLogs', 0));

        $this->start($admin, $target, $org);
        $this->get(route('emails'));
        $this->post(route('impersonate.leave'));

        $this->get(route('admin.accounts.show', $org))
            ->assertInertia(fn ($page) => $page
                ->has('impersonationLogs', 1)
                ->where('impersonationLogs.0.end_reason', 'left')
                ->where('impersonationLogs.0.reason', self::REASON)
                ->where('impersonationLogs.0.admin', 'Ada Admin')
                ->where('impersonationLogs.0.actions_count', 2));
    }

    public function test_every_mutating_web_route_is_blocked_except_leave_and_logout(): void
    {
        $guard = app(ImpersonationGuard::class);
        $shouldBlock = new ReflectionMethod($guard, 'shouldBlock');
        $allowed = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            if (! in_array('web', $route->gatherMiddleware(), true)) {
                continue;
            }

            foreach (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) as $method) {
                $request = Request::create('/'.ltrim($route->uri(), '/'), $method);
                $request->setRouteResolver(fn () => $route);
                $checked++;

                if (! $shouldBlock->invoke($guard, $request)) {
                    $allowed[] = $route->getName();
                }
            }
        }

        $this->assertGreaterThan(20, $checked);
        sort($allowed);
        $this->assertSame(['impersonate.leave', 'logout'], array_values(array_unique($allowed)));
    }

    public function test_impersonated_user_is_never_notified(): void
    {
        Mail::fake();
        Notification::fake();
        Queue::fake();

        [$admin, $target, $org] = $this->scenario();
        $target->forceFill(['remember_token' => 'target-remember-token'])->save();
        $before = $target->fresh()->getAttributes();

        $this->start($admin, $target, $org);
        $this->get(route('emails'))->assertOk();
        $this->post(route('emails.store'), ['from' => 'a@acme.test', 'to' => 'b@example.com', 'subject' => 'Hi', 'text' => 'x'])
            ->assertForbidden();
        $this->post(route('impersonate.leave'));

        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();

        // The target's own record (remember token, timestamps…) is untouched,
        // and nothing lands in tenant-visible tables.
        $this->assertSame($before, $target->fresh()->getAttributes());
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    public function test_every_request_records_ip_and_user_agent(): void
    {
        [$admin, $target, $org] = $this->scenario();
        $this->start($admin, $target, $org);

        $this->withHeaders(['User-Agent' => 'AuditAgent/1.0'])
            ->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
            ->get(route('emails'))->assertOk();
        $this->withHeaders(['User-Agent' => 'AuditAgent/1.0'])
            ->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
            ->post(route('api-keys.store'), ['name' => 'Key'])->assertForbidden();

        $log = $this->log();
        $this->assertDatabaseHas('impersonation_actions', [
            'impersonation_log_id' => $log->id, 'route_name' => 'emails', 'blocked' => false,
            'ip' => '10.1.2.3', 'user_agent' => 'AuditAgent/1.0',
        ]);
        $this->assertDatabaseHas('impersonation_actions', [
            'impersonation_log_id' => $log->id, 'route_name' => 'api-keys.store', 'blocked' => true,
            'status' => 403, 'ip' => '10.1.2.3', 'user_agent' => 'AuditAgent/1.0',
        ]);
    }

    public function test_denied_start_attempts_are_audited(): void
    {
        [$admin, , $org] = $this->scenario();
        $otherAdmin = User::factory()->platformAdmin()->create();
        $org->users()->attach($otherAdmin->id, ['role' => 'member']);

        $this->start($admin, $otherAdmin, $org)->assertSessionHasErrors('user');

        $log = $this->log();
        $this->assertSame('denied', $log->end_reason);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame($otherAdmin->id, $log->user_id);
        $this->assertSame($org->id, $log->organization_id);
        $this->assertSame(self::REASON, $log->reason);
        $this->assertSame('Platform admins cannot be impersonated.', $log->denied_reason);
        $this->assertSame('127.0.0.1', $log->ip);
        $this->assertNotNull($log->ended_at);
        $this->assertSame($admin->id, Auth::id());
    }

    public function test_workspace_owner_can_impersonate_teammate(): void
    {
        $owner = User::factory()->create(['name' => 'Olivia Owner']);
        $member = User::factory()->create(['name' => 'Mia Member', 'email' => 'mia@acme.test']);
        $org = Organization::factory()->create(['subdomain' => 'acme']);
        $org->users()->attach($owner->id, ['role' => 'owner']);
        $org->users()->attach($member->id, ['role' => 'member']);
        Mailbox::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $member->id,
            'email' => 'mia@acme.test',
            'inbox' => true,
            'transactional' => true,
            'status' => 'active',
        ]);

        $this->actingAs($owner)
            ->withSession([
                'current_organization_id' => $org->id,
                'auth.password_confirmed_at' => time(),
            ])
            ->from(route('users'))
            ->post(route('team.impersonate', $member), ['reason' => self::REASON])
            ->assertRedirect('http://acme.maildesk.test/inbox');

        $this->assertSame($member->id, Auth::id());
        $this->assertSame($owner->id, session('impersonator_id'));
        $this->assertSame($org->id, session('current_organization_id'));

        $log = $this->log();
        $this->assertSame($owner->id, $log->admin_id);
        $this->assertSame($member->id, $log->user_id);
        $this->assertSame($org->id, $log->organization_id);
        $this->assertNull($log->ended_at);

        $this->get(route('emails'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('impersonation.active', true)
                ->where('impersonation.return_label', 'Return to workspace')
                ->etc());

        $this->post(route('impersonate.leave'))
            ->assertRedirect('http://acme.maildesk.test/settings/team');

        $this->assertSame($owner->id, Auth::id());
        $this->assertNull(session('impersonator_id'));
        $this->assertSame($org->id, session('current_organization_id'));
        $this->assertSame('left', $this->log()->end_reason);
    }

    public function test_workspace_admin_cannot_impersonate_owner_or_other_admin(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $otherAdmin = User::factory()->create();
        $member = User::factory()->create(['email' => 'member@acme.test']);
        $org = Organization::factory()->create(['subdomain' => 'acme']);
        $org->users()->attach($owner->id, ['role' => 'owner']);
        $org->users()->attach($admin->id, ['role' => 'admin']);
        $org->users()->attach($otherAdmin->id, ['role' => 'admin']);
        $org->users()->attach($member->id, ['role' => 'member']);
        Mailbox::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $member->id,
            'email' => 'member@acme.test',
            'inbox' => true,
            'status' => 'active',
        ]);

        $session = [
            'current_organization_id' => $org->id,
            'auth.password_confirmed_at' => time(),
        ];

        $this->actingAs($admin)
            ->withSession($session)
            ->from(route('users'))
            ->post(route('team.impersonate', $owner), ['reason' => self::REASON])
            ->assertSessionHasErrors('user');

        $this->assertSame($admin->id, Auth::id());
        $this->assertSame('denied', $this->log()->end_reason);
        $this->assertSame('Admins can only log in as members.', $this->log()->denied_reason);

        $this->actingAs($admin)
            ->withSession($session)
            ->from(route('users'))
            ->post(route('team.impersonate', $otherAdmin), ['reason' => self::REASON])
            ->assertSessionHasErrors('user');

        $this->actingAs($admin)
            ->withSession($session)
            ->from(route('users'))
            ->post(route('team.impersonate', $member), ['reason' => self::REASON])
            ->assertRedirect('http://acme.maildesk.test/inbox');

        $this->assertSame($member->id, Auth::id());
    }

    public function test_workspace_member_cannot_impersonate(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $teammate = User::factory()->create();
        $org = Organization::factory()->create(['subdomain' => 'acme']);
        $org->users()->attach($owner->id, ['role' => 'owner']);
        $org->users()->attach($member->id, ['role' => 'member']);
        $org->users()->attach($teammate->id, ['role' => 'member']);

        $this->actingAs($member)
            ->withSession([
                'current_organization_id' => $org->id,
                'auth.password_confirmed_at' => time(),
            ])
            ->from(route('users'))
            ->post(route('team.impersonate', $teammate), ['reason' => self::REASON])
            ->assertForbidden();

        $this->assertSame($member->id, Auth::id());
        $this->assertNull(session('impersonator_id'));
    }

    public function test_settings_team_page_lists_accounts_for_owners_and_hides_login_as_for_members(): void
    {
        $owner = User::factory()->create(['name' => 'Olivia Owner']);
        $member = User::factory()->create(['name' => 'Mia Member', 'email' => 'mia@acme.test']);
        $org = Organization::factory()->create();
        $org->users()->attach($owner->id, ['role' => 'owner']);
        $org->users()->attach($member->id, ['role' => 'member']);

        $this->actingAs($owner)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('settings', 'team'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Index')
                ->where('tab', 'team')
                ->where('canImpersonateTeam', true)
                ->has('team', 2)
                ->where('team.0.id', $owner->id)
                ->where('team.1.id', $member->id)
                ->where('team.1.email', 'mia@acme.test'));

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('settings', 'team'))
            ->assertForbidden();
    }

    public function test_cannot_impersonate_user_outside_current_workspace(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $org = Organization::factory()->create(['subdomain' => 'acme']);
        $other = Organization::factory()->create(['subdomain' => 'other']);
        $org->users()->attach($owner->id, ['role' => 'owner']);
        $other->users()->attach($outsider->id, ['role' => 'owner']);

        $this->actingAs($owner)
            ->withSession([
                'current_organization_id' => $org->id,
                'auth.password_confirmed_at' => time(),
            ])
            ->from(route('users'))
            ->post(route('team.impersonate', $outsider), ['reason' => self::REASON])
            ->assertSessionHasErrors('user');

        $this->assertSame('This user is not a member of this workspace.', $this->log()->denied_reason);
        $this->assertSame($owner->id, Auth::id());
    }

    public function test_denied_attempt_on_suspended_account_is_audited(): void
    {
        [$admin, $target, $org] = $this->scenario();
        $org->update(['status' => 'suspended']);

        $this->start($admin, $target, $org)->assertSessionHasErrors('user');

        $log = $this->log();
        $this->assertSame('denied', $log->end_reason);
        $this->assertSame($target->id, $log->user_id);
        $this->assertSame($org->id, $log->organization_id);
        $this->assertStringStartsWith('Cannot log in as a user of this account', $log->denied_reason);
        $this->assertSame(0, ImpersonationLog::query()->open()->count());
        $this->assertSame($admin->id, Auth::id());
    }
}
