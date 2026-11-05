<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Imap\StalwartClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminBlockedIpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * @return list<array{id: string, ip: string, reason: string, created: ?string}>
     */
    protected function blockedSample(): array
    {
        return [
            [
                'id' => 'ban-1',
                'ip' => '203.0.113.10',
                'reason' => 'authFailure',
                'created' => '2026-10-28T12:00:00+00:00',
            ],
            [
                'id' => 'ban-2',
                'ip' => '198.51.100.20',
                'reason' => 'manual',
                'created' => null,
            ],
        ];
    }

    protected function fakeStalwart(?array $entries = null, bool $enabled = true): StalwartClient
    {
        $entries ??= $this->blockedSample();
        $client = $this->mock(StalwartClient::class);
        $client->shouldReceive('enabled')->andReturn($enabled);
        $client->shouldReceive('listBlockedIps')->andReturn($entries);
        $client->shouldReceive('matchesBlockedAddress')->andReturnUsing(
            fn (string $blocked, string $ip) => $blocked === $ip,
        );
        $client->shouldReceive('findBlockedIp')->andReturnUsing(function (string $ip) use ($entries) {
            foreach ($entries as $entry) {
                if ($entry['ip'] === $ip) {
                    return $entry;
                }
            }

            return null;
        });

        return $client;
    }

    public function test_guest_is_redirected_from_blocked_ips(): void
    {
        $this->get(route('admin.blocked-ips'))
            ->assertRedirect(route('login'));
    }

    public function test_non_admin_receives_403_for_blocked_ips(): void
    {
        $user = User::factory()->create(['is_platform_admin' => false]);

        $this->actingAs($user)
            ->get(route('admin.blocked-ips'))
            ->assertForbidden();
    }

    public function test_non_admin_receives_403_when_unblocking(): void
    {
        $user = User::factory()->create(['is_platform_admin' => false]);

        $this->actingAs($user)
            ->post(route('admin.blocked-ips.unblock'), ['ip' => '203.0.113.10'])
            ->assertForbidden();
    }

    public function test_platform_admin_sees_blocked_ip_list_and_current_ip_flag(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $this->fakeStalwart();

        $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get(route('admin.blocked-ips'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/BlockedIps/Index')
                ->where('currentIp', '203.0.113.10')
                ->where('enabled', true)
                ->has('entries', 2)
                ->where('entries.0.ip', '203.0.113.10')
                ->where('entries.0.reason', 'authFailure')
                ->where('entries.0.reason_label', 'Failed logins')
                ->where('entries.0.is_current', true)
                ->where('entries.1.ip', '198.51.100.20')
                ->where('entries.1.is_current', false));
    }

    public function test_platform_admin_unblocks_an_ip(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $client = $this->fakeStalwart();
        $client->shouldReceive('unblockIp')->once()->with('203.0.113.10');

        $this->actingAs($admin)
            ->from(route('admin.blocked-ips'))
            ->post(route('admin.blocked-ips.unblock'), ['ip' => '203.0.113.10'])
            ->assertRedirect(route('admin.blocked-ips'))
            ->assertSessionHas('success', 'Unblocked 203.0.113.10.');
    }

    public function test_unblock_rejects_an_invalid_ip(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $this->fakeStalwart();

        $this->actingAs($admin)
            ->from(route('admin.blocked-ips'))
            ->post(route('admin.blocked-ips.unblock'), ['ip' => 'not-an-ip'])
            ->assertRedirect(route('admin.blocked-ips'))
            ->assertSessionHasErrors('ip');
    }

    public function test_unblock_failure_flashes_an_error(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $client = $this->fakeStalwart();
        $client->shouldReceive('unblockIp')
            ->once()
            ->with('203.0.113.10')
            ->andThrow(new \RuntimeException('mail server unavailable'));

        $this->actingAs($admin)
            ->from(route('admin.blocked-ips'))
            ->post(route('admin.blocked-ips.unblock'), ['ip' => '203.0.113.10'])
            ->assertRedirect(route('admin.blocked-ips'))
            ->assertSessionHas('error', 'Could not unblock that IP. Try again.');
    }

    public function test_mail_clients_page_shows_blocked_notice_and_support_copy_for_tenants(): void
    {
        $user = User::factory()->create(['is_platform_admin' => false]);
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $this->fakeStalwart();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get(route('settings', 'mail-clients'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Index')
                ->where('tab', 'mail-clients')
                ->where('mailClients.blocked.blocked', true)
                ->where('mailClients.blocked.ip', '203.0.113.10')
                ->where('mailClients.blocked.can_unblock', false)
                ->where('mailClients.blocked.reason', 'authFailure'));
    }

    public function test_mail_clients_page_hides_notice_when_ip_is_not_blocked(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $this->fakeStalwart();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])
            ->get(route('settings', 'mail-clients'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('mailClients.blocked.blocked', false)
                ->where('mailClients.blocked.ip', '192.0.2.50'));
    }

    public function test_mail_clients_page_lets_platform_admins_unblock(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($admin->id, ['role' => 'owner']);
        $this->fakeStalwart();

        $this->actingAs($admin)
            ->withSession(['current_organization_id' => $org->id])
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get(route('settings', 'mail-clients'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Index')
                ->where('mailClients.blocked.blocked', true)
                ->where('mailClients.blocked.can_unblock', true));
    }
}
