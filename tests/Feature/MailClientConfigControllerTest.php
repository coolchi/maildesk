<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailClientConfigControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function enableStalwart(): void
    {
        config([
            'maildesk.stalwart.enabled' => true,
            'maildesk.stalwart.url' => 'http://stalwart.test',
            'maildesk.stalwart.admin_user' => 'admin@test',
            'maildesk.stalwart.admin_password' => 'secret',
            'maildesk.stalwart.clients.imap.host' => 'mail.test',
            'maildesk.stalwart.clients.imap.port' => 993,
            'maildesk.stalwart.clients.imap.encryption' => 'ssl',
            'maildesk.stalwart.clients.smtp.host' => 'mail.test',
            'maildesk.stalwart.clients.smtp.port' => 465,
            'maildesk.stalwart.clients.smtp.encryption' => 'ssl',
            'maildesk.stalwart.clients.pop.host' => 'mail.test',
            'maildesk.stalwart.clients.pop.port' => 995,
            'maildesk.stalwart.clients.pop.encryption' => 'ssl',
        ]);
    }

    public function test_mobileconfig_download_requires_authentication(): void
    {
        $this->enableStalwart();
        $org = Organization::factory()->create();
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'imap_enabled' => true,
        ]);

        $this->get(route('settings.mail-clients.mobileconfig', $mailbox->id))
            ->assertRedirect(route('login'));
    }

    public function test_mobileconfig_download_returns_plist_for_own_mailbox(): void
    {
        $this->enableStalwart();
        [$user, $org] = $this->teamUser();
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'test@example.com',
            'imap_enabled' => true,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('settings.mail-clients.mobileconfig', $mailbox->id));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/x-apple-aspen-config');

        $this->assertStringContainsString('.mobileconfig', $response->headers->get('Content-Disposition'));

        $content = $response->getContent();
        $this->assertStringContainsString('<?xml version="1.0"', $content);
        $this->assertStringContainsString('com.apple.mail.managed', $content);
        $this->assertStringContainsString('test@example.com', $content);
        $this->assertStringContainsString('mail.test', $content);
        $this->assertStringContainsString('<integer>993</integer>', $content);
        $this->assertStringContainsString('<integer>465</integer>', $content);
    }

    public function test_mobileconfig_download_denies_other_org_mailbox(): void
    {
        $this->enableStalwart();
        [$user, $org] = $this->teamUser();
        $otherOrg = Organization::factory()->create();
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $otherOrg->id,
            'imap_enabled' => true,
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('settings.mail-clients.mobileconfig', $mailbox->id))
            ->assertNotFound();
    }

    public function test_mobileconfig_download_denies_imap_disabled_mailbox(): void
    {
        $this->enableStalwart();
        [$user, $org] = $this->teamUser();
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'imap_enabled' => false,
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('settings.mail-clients.mobileconfig', $mailbox->id))
            ->assertForbidden();
    }

    public function test_autoconfig_download_returns_xml_for_own_mailbox(): void
    {
        $this->enableStalwart();
        [$user, $org] = $this->teamUser();
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'hello@acme.test',
            'imap_enabled' => true,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('settings.mail-clients.autoconfig', $mailbox->id));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->assertHeader('Content-Disposition', 'attachment; filename="config-v1.1.xml"');

        $content = $response->getContent();
        $this->assertStringContainsString('<clientConfig version="1.1">', $content);
        $this->assertStringContainsString('<emailProvider id="acme.test">', $content);
        $this->assertStringContainsString('<incomingServer type="imap">', $content);
        $this->assertStringContainsString('<incomingServer type="pop3">', $content);
        $this->assertStringContainsString('<outgoingServer type="smtp">', $content);
        $this->assertStringContainsString('mail.test', $content);
        $this->assertStringContainsString('<port>993</port>', $content);
        $this->assertStringContainsString('<port>995</port>', $content);
        $this->assertStringContainsString('<port>465</port>', $content);
        $this->assertStringContainsString('<socketType>SSL</socketType>', $content);
    }

    public function test_autodiscover_download_returns_pox_xml(): void
    {
        $this->enableStalwart();
        [$user, $org] = $this->teamUser();
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'user@domain.test',
            'imap_enabled' => true,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('settings.mail-clients.autodiscover', $mailbox->id));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->assertHeader('Content-Disposition', 'attachment; filename="autodiscover.xml"');

        $content = $response->getContent();
        $this->assertStringContainsString('<Autodiscover', $content);
        $this->assertStringContainsString('<Type>IMAP</Type>', $content);
        $this->assertStringContainsString('<Type>POP3</Type>', $content);
        $this->assertStringContainsString('<Type>SMTP</Type>', $content);
        $this->assertStringContainsString('<Server>mail.test</Server>', $content);
        $this->assertStringContainsString('<Port>993</Port>', $content);
        $this->assertStringContainsString('<Port>995</Port>', $content);
        $this->assertStringContainsString('<Port>465</Port>', $content);
        $this->assertStringContainsString('<SSL>on</SSL>', $content);
    }

    public function test_autoconfig_public_endpoint_returns_xml_for_valid_email(): void
    {
        $this->enableStalwart();
        [$user, $org] = $this->teamUser();
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'valid@acme.test',
            'imap_enabled' => true,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('mail.autoconfig', ['emailaddress' => 'valid@acme.test']));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/xml');

        $content = $response->getContent();
        $this->assertStringContainsString('<clientConfig version="1.1">', $content);
        $this->assertStringContainsString('<domain>acme.test</domain>', $content);
    }

    public function test_autoconfig_public_endpoint_404_for_unknown_email(): void
    {
        $this->enableStalwart();
        [$user, $org] = $this->teamUser();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('mail.autoconfig', ['emailaddress' => 'unknown@nowhere.test']))
            ->assertNotFound();
    }

    public function test_autoconfig_public_endpoint_400_for_invalid_email(): void
    {
        $this->enableStalwart();
        [$user, $org] = $this->teamUser();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('mail.autoconfig', ['emailaddress' => 'not-an-email']))
            ->assertStatus(400);
    }

    public function test_autoconfig_public_endpoint_400_for_missing_email(): void
    {
        $this->enableStalwart();
        [$user, $org] = $this->teamUser();

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('mail.autoconfig'))
            ->assertStatus(400);
    }

    public function test_autoconfig_public_works_for_logged_out_requests(): void
    {
        $this->enableStalwart();
        $org = Organization::factory()->create();
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'hello@learna.ng',
            'imap_enabled' => true,
        ]);

        $response = $this->get(route('mail.autoconfig', ['emailaddress' => 'hello@learna.ng']));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/xml');

        $content = $response->getContent();
        $this->assertStringContainsString('<clientConfig version="1.1">', $content);
        $this->assertStringContainsString('<domain>learna.ng</domain>', $content);
        $this->assertStringContainsString('<incomingServer type="imap">', $content);
        $this->assertStringContainsString('<incomingServer type="pop3">', $content);
        $this->assertStringContainsString('<outgoingServer type="smtp">', $content);
    }

    public function test_autoconfig_public_returns_404_for_unknown_email_without_leaking_data(): void
    {
        $this->enableStalwart();

        $response = $this->get(route('mail.autoconfig', ['emailaddress' => 'unknown@example.com']));

        $response->assertNotFound();
    }

    public function test_autoconfig_wellknown_path_works(): void
    {
        $this->enableStalwart();
        $org = Organization::factory()->create();
        Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'test@acme.test',
            'imap_enabled' => true,
        ]);

        $response = $this->get('/.well-known/autoconfig/mail/config-v1.1.xml?emailaddress=test@acme.test');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/xml');

        $this->assertStringContainsString('<clientConfig version="1.1">', $response->getContent());
    }

    public function test_autodiscover_public_get_endpoint_works(): void
    {
        $this->enableStalwart();
        $org = Organization::factory()->create();
        Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'outlook@domain.test',
            'imap_enabled' => true,
        ]);

        $response = $this->get(route('mail.autodiscover', ['email' => 'outlook@domain.test']));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/xml');

        $content = $response->getContent();
        $this->assertStringContainsString('<Autodiscover', $content);
        $this->assertStringContainsString('<Type>IMAP</Type>', $content);
        $this->assertStringContainsString('<Type>POP3</Type>', $content);
        $this->assertStringContainsString('<Type>SMTP</Type>', $content);
    }

    public function test_autodiscover_public_post_endpoint_parses_xml(): void
    {
        $this->enableStalwart();
        $org = Organization::factory()->create();
        Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'user@office.test',
            'imap_enabled' => true,
        ]);

        $xmlBody = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/requestschema/2006">
    <Request>
        <EMailAddress>user@office.test</EMailAddress>
        <AcceptableResponseSchema>http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a</AcceptableResponseSchema>
    </Request>
</Autodiscover>
XML;

        $response = $this->post(route('mail.autodiscover'), [], [
            'Content-Type' => 'application/xml',
        ]);

        $response = $this->call('POST', route('mail.autodiscover'), [], [], [], [
            'CONTENT_TYPE' => 'application/xml',
        ], $xmlBody);

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/xml');

        $content = $response->getContent();
        $this->assertStringContainsString('<Autodiscover', $content);
        $this->assertStringContainsString('user@office.test', $content);
    }

    public function test_autodiscover_public_returns_404_for_unknown_email(): void
    {
        $this->enableStalwart();

        $response = $this->get(route('mail.autodiscover', ['email' => 'nonexistent@void.test']));

        $response->assertNotFound();
    }

    public function test_mobileconfig_xml_is_valid_plist(): void
    {
        $this->enableStalwart();
        [$user, $org] = $this->teamUser();
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'test@example.com',
            'display_name' => 'Test User',
            'imap_enabled' => true,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('settings.mail-clients.mobileconfig', $mailbox->id));

        $content = $response->getContent();

        $xml = @simplexml_load_string($content);
        $this->assertNotFalse($xml, 'Mobileconfig XML should be valid');
        $this->assertEquals('plist', $xml->getName());
    }

    public function test_autoconfig_xml_is_valid(): void
    {
        $this->enableStalwart();
        [$user, $org] = $this->teamUser();
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'test@example.com',
            'imap_enabled' => true,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('settings.mail-clients.autoconfig', $mailbox->id));

        $content = $response->getContent();

        $xml = @simplexml_load_string($content);
        $this->assertNotFalse($xml, 'Autoconfig XML should be valid');
        $this->assertEquals('clientConfig', $xml->getName());
    }

    public function test_autodiscover_xml_is_valid(): void
    {
        $this->enableStalwart();
        [$user, $org] = $this->teamUser();
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'test@example.com',
            'imap_enabled' => true,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get(route('settings.mail-clients.autodiscover', $mailbox->id));

        $content = $response->getContent();

        $xml = @simplexml_load_string($content);
        $this->assertNotFalse($xml, 'Autodiscover XML should be valid');
        $this->assertEquals('Autodiscover', $xml->getName());
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
