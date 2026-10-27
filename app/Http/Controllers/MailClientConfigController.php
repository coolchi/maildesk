<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Services\Imap\MailClientSettings;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class MailClientConfigController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected MailClientSettings $mailSettings,
    ) {}

    /**
     * Apple Mail / iOS .mobileconfig profile.
     */
    public function mobileconfig(Request $request, Mailbox $mailbox): Response
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($mailbox->organization_id === $organization->id, 404);
        abort_unless($mailbox->imap_enabled, 403, 'IMAP is not enabled for this mailbox.');

        $config = config('maildesk.stalwart.clients', []);
        $imap = $config['imap'] ?? [];
        $smtp = $config['smtp'] ?? [];
        $provider = config('app.name', 'MailDesk');

        $uuid = (string) Str::uuid();
        $accountUuid = (string) Str::uuid();

        $xml = $this->renderMobileconfig([
            'provider' => $provider,
            'email' => $mailbox->email,
            'display_name' => $mailbox->display_name ?: $mailbox->email,
            'uuid' => strtoupper($uuid),
            'account_uuid' => strtoupper($accountUuid),
            'imap' => [
                'host' => $imap['host'] ?? 'mail.localhost',
                'port' => $imap['port'] ?? 993,
                'security' => $imap['encryption'] ?? 'ssl',
            ],
            'smtp' => [
                'host' => $smtp['host'] ?? 'mail.localhost',
                'port' => $smtp['port'] ?? 465,
                'security' => $smtp['encryption'] ?? 'ssl',
            ],
        ]);

        $filename = Str::slug($mailbox->email).'.mobileconfig';

        return response($xml, 200, [
            'Content-Type' => 'application/x-apple-aspen-config',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Thunderbird / Evolution autoconfig XML.
     */
    public function autoconfig(Request $request, Mailbox $mailbox): Response
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($mailbox->organization_id === $organization->id, 404);
        abort_unless($mailbox->imap_enabled, 403, 'IMAP is not enabled for this mailbox.');

        $config = config('maildesk.stalwart.clients', []);
        $imap = $config['imap'] ?? [];
        $smtp = $config['smtp'] ?? [];
        $provider = config('app.name', 'MailDesk');

        $xml = $this->renderAutoconfig([
            'provider' => $provider,
            'provider_short' => $provider,
            'email' => $mailbox->email,
            'imap' => [
                'host' => $imap['host'] ?? 'mail.localhost',
                'port' => $imap['port'] ?? 993,
                'security' => $imap['encryption'] ?? 'ssl',
            ],
            'smtp' => [
                'host' => $smtp['host'] ?? 'mail.localhost',
                'port' => $smtp['port'] ?? 465,
                'security' => $smtp['encryption'] ?? 'ssl',
            ],
        ]);

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="config-v1.1.xml"',
        ]);
    }

    /**
     * Outlook autodiscover POX XML.
     */
    public function autodiscover(Request $request, Mailbox $mailbox): Response
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($mailbox->organization_id === $organization->id, 404);
        abort_unless($mailbox->imap_enabled, 403, 'IMAP is not enabled for this mailbox.');

        $config = config('maildesk.stalwart.clients', []);
        $imap = $config['imap'] ?? [];
        $smtp = $config['smtp'] ?? [];
        $provider = config('app.name', 'MailDesk');

        $xml = $this->renderAutodiscover([
            'provider' => $provider,
            'email' => $mailbox->email,
            'display_name' => $mailbox->display_name ?: $mailbox->email,
            'imap' => [
                'host' => $imap['host'] ?? 'mail.localhost',
                'port' => $imap['port'] ?? 993,
                'security' => $imap['encryption'] ?? 'ssl',
            ],
            'smtp' => [
                'host' => $smtp['host'] ?? 'mail.localhost',
                'port' => $smtp['port'] ?? 465,
                'security' => $smtp['encryption'] ?? 'ssl',
            ],
        ]);

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="autodiscover.xml"',
        ]);
    }

    /**
     * @param  array{provider: string, email: string, display_name: string, uuid: string, account_uuid: string, imap: array{host: string, port: int, security: string}, smtp: array{host: string, port: int, security: string}}  $data
     */
    protected function renderMobileconfig(array $data): string
    {
        $sslImap = $data['imap']['security'] === 'ssl';
        $sslSmtp = $data['smtp']['security'] === 'ssl';
        $email = htmlspecialchars($data['email'], ENT_XML1);
        $displayName = htmlspecialchars($data['display_name'], ENT_XML1);
        $provider = htmlspecialchars($data['provider'], ENT_XML1);
        $imapHost = htmlspecialchars($data['imap']['host'], ENT_XML1);
        $smtpHost = htmlspecialchars($data['smtp']['host'], ENT_XML1);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>PayloadContent</key>
    <array>
        <dict>
            <key>EmailAccountDescription</key>
            <string>{$displayName}</string>
            <key>EmailAccountName</key>
            <string>{$displayName}</string>
            <key>EmailAccountType</key>
            <string>EmailTypeIMAP</string>
            <key>EmailAddress</key>
            <string>{$email}</string>
            <key>IncomingMailServerAuthentication</key>
            <string>EmailAuthPassword</string>
            <key>IncomingMailServerHostName</key>
            <string>{$imapHost}</string>
            <key>IncomingMailServerPortNumber</key>
            <integer>{$data['imap']['port']}</integer>
            <key>IncomingMailServerUseSSL</key>
            <{$this->boolToXml($sslImap)}/>
            <key>IncomingMailServerUsername</key>
            <string>{$email}</string>
            <key>OutgoingMailServerAuthentication</key>
            <string>EmailAuthPassword</string>
            <key>OutgoingMailServerHostName</key>
            <string>{$smtpHost}</string>
            <key>OutgoingMailServerPortNumber</key>
            <integer>{$data['smtp']['port']}</integer>
            <key>OutgoingMailServerUseSSL</key>
            <{$this->boolToXml($sslSmtp)}/>
            <key>OutgoingMailServerUsername</key>
            <string>{$email}</string>
            <key>OutgoingPasswordSameAsIncomingPassword</key>
            <true/>
            <key>PayloadDescription</key>
            <string>Configures email account for {$email}</string>
            <key>PayloadDisplayName</key>
            <string>{$provider} - {$email}</string>
            <key>PayloadIdentifier</key>
            <string>com.maildesk.email.{$data['account_uuid']}</string>
            <key>PayloadType</key>
            <string>com.apple.mail.managed</string>
            <key>PayloadUUID</key>
            <string>{$data['account_uuid']}</string>
            <key>PayloadVersion</key>
            <integer>1</integer>
        </dict>
    </array>
    <key>PayloadDescription</key>
    <string>Email configuration for {$email}</string>
    <key>PayloadDisplayName</key>
    <string>{$provider} Mail</string>
    <key>PayloadIdentifier</key>
    <string>com.maildesk.profile.{$data['uuid']}</string>
    <key>PayloadOrganization</key>
    <string>{$provider}</string>
    <key>PayloadRemovalDisallowed</key>
    <false/>
    <key>PayloadType</key>
    <string>Configuration</string>
    <key>PayloadUUID</key>
    <string>{$data['uuid']}</string>
    <key>PayloadVersion</key>
    <integer>1</integer>
</dict>
</plist>
XML;
    }

    protected function boolToXml(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * @param  array{provider: string, provider_short: string, email: string, imap: array{host: string, port: int, security: string}, smtp: array{host: string, port: int, security: string}}  $data
     */
    protected function renderAutoconfig(array $data): string
    {
        $imapSocketType = $this->securityToSocketType($data['imap']['security']);
        $smtpSocketType = $this->securityToSocketType($data['smtp']['security']);
        $provider = htmlspecialchars($data['provider'], ENT_XML1);
        $providerShort = htmlspecialchars($data['provider_short'], ENT_XML1);
        $imapHost = htmlspecialchars($data['imap']['host'], ENT_XML1);
        $smtpHost = htmlspecialchars($data['smtp']['host'], ENT_XML1);
        $domain = substr(strrchr($data['email'], '@') ?: '@maildesk.ng', 1);
        $domainEscaped = htmlspecialchars($domain, ENT_XML1);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<clientConfig version="1.1">
    <emailProvider id="{$domainEscaped}">
        <domain>{$domainEscaped}</domain>
        <displayName>{$provider}</displayName>
        <displayShortName>{$providerShort}</displayShortName>
        <incomingServer type="imap">
            <hostname>{$imapHost}</hostname>
            <port>{$data['imap']['port']}</port>
            <socketType>{$imapSocketType}</socketType>
            <authentication>password-cleartext</authentication>
            <username>%EMAILADDRESS%</username>
        </incomingServer>
        <outgoingServer type="smtp">
            <hostname>{$smtpHost}</hostname>
            <port>{$data['smtp']['port']}</port>
            <socketType>{$smtpSocketType}</socketType>
            <authentication>password-cleartext</authentication>
            <username>%EMAILADDRESS%</username>
        </outgoingServer>
    </emailProvider>
</clientConfig>
XML;
    }

    protected function securityToSocketType(string $security): string
    {
        return match ($security) {
            'ssl' => 'SSL',
            'tls', 'starttls' => 'STARTTLS',
            default => 'plain',
        };
    }

    /**
     * @param  array{provider: string, email: string, display_name: string, imap: array{host: string, port: int, security: string}, smtp: array{host: string, port: int, security: string}}  $data
     */
    protected function renderAutodiscover(array $data): string
    {
        $sslImap = $data['imap']['security'] === 'ssl' ? 'on' : 'off';
        $sslSmtp = $data['smtp']['security'] === 'ssl' ? 'on' : 'off';
        $email = htmlspecialchars($data['email'], ENT_XML1);
        $imapHost = htmlspecialchars($data['imap']['host'], ENT_XML1);
        $smtpHost = htmlspecialchars($data['smtp']['host'], ENT_XML1);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006">
    <Response xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a">
        <Account>
            <AccountType>email</AccountType>
            <Action>settings</Action>
            <Protocol>
                <Type>IMAP</Type>
                <Server>{$imapHost}</Server>
                <Port>{$data['imap']['port']}</Port>
                <SSL>{$sslImap}</SSL>
                <LoginName>{$email}</LoginName>
                <DomainRequired>off</DomainRequired>
                <SPA>off</SPA>
                <AuthRequired>on</AuthRequired>
            </Protocol>
            <Protocol>
                <Type>SMTP</Type>
                <Server>{$smtpHost}</Server>
                <Port>{$data['smtp']['port']}</Port>
                <SSL>{$sslSmtp}</SSL>
                <LoginName>{$email}</LoginName>
                <DomainRequired>off</DomainRequired>
                <SPA>off</SPA>
                <AuthRequired>on</AuthRequired>
                <UsePOPAuth>on</UsePOPAuth>
                <SMTPLast>off</SMTPLast>
            </Protocol>
        </Account>
    </Response>
</Autodiscover>
XML;
    }

    /**
     * Standard Thunderbird autoconfig endpoint (uses email query param).
     */
    public function autoconfigPublic(Request $request): Response
    {
        $email = $request->query('emailaddress');
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            abort(400, 'Invalid or missing emailaddress parameter.');
        }

        $organization = CurrentOrganization::from($request);
        $mailbox = $organization->mailboxes()
            ->where('email', $email)
            ->where('imap_enabled', true)
            ->first();

        if (! $mailbox) {
            abort(404, 'No mailbox found for this email address.');
        }

        $config = config('maildesk.stalwart.clients', []);
        $imap = $config['imap'] ?? [];
        $smtp = $config['smtp'] ?? [];
        $provider = config('app.name', 'MailDesk');

        $xml = $this->renderAutoconfig([
            'provider' => $provider,
            'provider_short' => $provider,
            'email' => $mailbox->email,
            'imap' => [
                'host' => $imap['host'] ?? 'mail.localhost',
                'port' => $imap['port'] ?? 993,
                'security' => $imap['encryption'] ?? 'ssl',
            ],
            'smtp' => [
                'host' => $smtp['host'] ?? 'mail.localhost',
                'port' => $smtp['port'] ?? 465,
                'security' => $smtp['encryption'] ?? 'ssl',
            ],
        ]);

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
        ]);
    }
}
