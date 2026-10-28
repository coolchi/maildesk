<?php

namespace Tests\Feature;

use App\Mail\MailManager;
use App\Mail\Providers\ArrayProvider;
use App\Mail\Providers\SmtpProvider;
use App\Models\Mailbox;
use App\Models\Organization;
use App\Models\User;
use App\Services\Imap\MailboxProvisioner;
use App\Services\Imap\StalwartClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StalwartMailClientsTest extends TestCase
{
    use RefreshDatabase;

    protected function enableStalwart(): void
    {
        config([
            'maildesk.stalwart.enabled' => true,
            'maildesk.stalwart.url' => 'http://stalwart.test',
            'maildesk.stalwart.admin_user' => 'admin@test',
            'maildesk.stalwart.admin_password' => 'secret',
            'maildesk.stalwart.auto_provision' => true,
            'maildesk.stalwart.clients.smtp.host' => 'mail.test',
            'maildesk.stalwart.clients.smtp.port' => 465,
            'maildesk.stalwart.clients.smtp.encryption' => 'ssl',
            'maildesk.fake_send' => false,
        ]);
    }

    public function test_settings_mail_clients_tab_renders(): void
    {
        $this->enableStalwart();
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('settings', 'mail-clients'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Index')
                ->where('tab', 'mail-clients')
                ->where('mailClients.enabled', true));
    }

    public function test_provisioner_creates_stalwart_account(): void
    {
        $this->enableStalwart();

        Http::fake([
            'stalwart.test/jmap' => Http::sequence()
                ->push([
                    'methodResponses' => [[
                        'x:Domain/get',
                        ['list' => [['id' => 'dom1', 'name' => 'acme.test']], 'notFound' => []],
                        'd0',
                    ]],
                ])
                ->push([
                    'methodResponses' => [[
                        'x:Account/get',
                        ['list' => [], 'notFound' => []],
                        'a0',
                    ]],
                ])
                ->push([
                    'methodResponses' => [[
                        'x:Account/set',
                        ['created' => ['new' => ['id' => 'acc1']]],
                        'a1',
                    ]],
                ])
                ->push([
                    'methodResponses' => [[
                        'x:Account/set',
                        ['updated' => ['acc1' => null]],
                        'a2',
                    ]],
                ]),
        ]);

        $org = Organization::factory()->create();
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'hello@acme.test',
            'status' => 'active',
        ]);

        $password = app(MailboxProvisioner::class)->provision($mailbox);

        $this->assertNotEmpty($password);
        $this->assertTrue($mailbox->fresh()->imap_enabled);
        $this->assertSame('acc1', $mailbox->fresh()->stalwart_account_id);
        $this->assertSame($password, $mailbox->fresh()->imap_password);
    }

    public function test_mail_manager_uses_mailbox_smtp_when_imap_enabled(): void
    {
        $this->enableStalwart();

        $org = Organization::factory()->create();
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'hello@acme.test',
            'imap_enabled' => true,
            'imap_password' => 'app-secret',
        ]);

        $provider = app(MailManager::class)->forMailbox($org, $mailbox);

        $this->assertInstanceOf(SmtpProvider::class, $provider);
    }

    public function test_mail_manager_falls_back_when_imap_disabled(): void
    {
        config(['maildesk.fake_send' => true]);

        $org = Organization::factory()->create();
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'imap_enabled' => false,
        ]);

        $provider = app(MailManager::class)->forMailbox($org, $mailbox);

        $this->assertInstanceOf(ArrayProvider::class, $provider);
    }

    public function test_stalwart_client_reports_disabled_without_config(): void
    {
        config(['maildesk.stalwart.enabled' => false]);

        $this->assertFalse(app(StalwartClient::class)->enabled());
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    protected function teamUser(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $org];
    }
}
