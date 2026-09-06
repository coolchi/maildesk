<?php

namespace Database\Seeders;

use App\Models\ApiKey;
use App\Models\Automation;
use App\Models\AutomationStep;
use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\Suppression;
use App\Models\Template;
use App\Models\Thread;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Database\Seeder;

class TenantWorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::query()->where('slug', 'acme-mail')->first();

        if (! $org) {
            return;
        }

        $admin = User::query()->where('email', 'ade@test.com')->first();
        $member = User::query()->where('email', 'member@test.com')->first();

        if ($member && ! $org->users()->whereKey($member->id)->exists()) {
            $org->users()->attach($member->id, ['role' => 'member']);
        }

        $this->seedProductFeatures($org);
        $this->seedMailboxUsers($org);

        if ($org->domains()->exists()) {
            return;
        }

        $acmeDns = Domain::defaultDnsRecords('acme.com');
        $acmeDns['checks'] = ['spf' => true, 'dkim' => true, 'dmarc' => true];

        $acme = Domain::query()->create([
            'organization_id' => $org->id,
            'name' => 'acme.com',
            'status' => 'verified',
            'provider' => $org->default_provider,
            'verified_at' => now()->subMonths(7),
            'dns_records' => $acmeDns,
            'created_at' => now()->subMonths(7),
        ]);

        $mailDns = Domain::defaultDnsRecords('mail.acme.com');
        $mailDns['checks'] = ['spf' => true, 'dkim' => true, 'dmarc' => false];

        Domain::query()->create([
            'organization_id' => $org->id,
            'name' => 'mail.acme.com',
            'status' => 'verified',
            'provider' => $org->default_provider,
            'verified_at' => now()->subMonths(6),
            'dns_records' => $mailDns,
            'created_at' => now()->subMonths(6),
        ]);

        $updatesDns = Domain::defaultDnsRecords('updates.acme.com');
        $updatesDns['checks'] = ['spf' => true, 'dkim' => false, 'dmarc' => false];

        Domain::query()->create([
            'organization_id' => $org->id,
            'name' => 'updates.acme.com',
            'status' => 'pending',
            'provider' => $org->default_provider,
            'dns_records' => $updatesDns,
            'created_at' => now()->subDays(9),
        ]);

        $mailbox = Mailbox::query()->create([
            'organization_id' => $org->id,
            'domain_id' => $acme->id,
            'email' => 'support@acme.com',
            'display_name' => 'Acme Support',
            'type' => 'shared',
            'role' => 'admin',
            'status' => 'active',
            'inbox' => true,
            'transactional' => true,
            'marketing' => false,
            'send_limit' => 5000,
        ]);

        ApiKey::issue($org, 'Production', $admin, ['*']);
        ApiKey::issue($org, 'Staging', $admin, ['emails:send', 'domain:acme.com']);
        ApiKey::issue($org, 'Local Dev', $admin, ['*']);

        Message::query()->create([
            'organization_id' => $org->id,
            'direction' => 'outbound',
            'status' => 'delivered',
            'provider' => 'resend',
            'from_email' => 'noreply@acme.com',
            'from_name' => 'Acme Admissions',
            'to' => [['email' => 'admin@depotterhealthtech.edu.ng']],
            'subject' => 'Reset your password',
            'html_body' => '<p>Hi there,</p><p>Click the button below to reset your password.</p>',
            'text_body' => 'Hi there, Click the link to reset your password.',
            'sent_at' => now()->subHours(21),
        ]);

        Message::query()->create([
            'organization_id' => $org->id,
            'direction' => 'outbound',
            'status' => 'delivered',
            'provider' => 'resend',
            'from_email' => 'billing@acme.com',
            'from_name' => 'Acme Billing',
            'to' => [['email' => 'admin@depotterhealthtech.edu.ng']],
            'subject' => 'Your receipt from Acme #1042',
            'html_body' => '<p>Thanks for your payment of <strong>$49.00</strong>.</p>',
            'text_body' => 'Thanks for your payment of $49.00.',
            'sent_at' => now()->subHours(22),
        ]);

        Message::query()->create([
            'organization_id' => $org->id,
            'direction' => 'outbound',
            'status' => 'bounced',
            'provider' => 'resend',
            'from_email' => 'alerts@acme.com',
            'from_name' => 'Acme Alerts',
            'to' => [['email' => 'ops@northwind.io']],
            'subject' => 'Deployment failed on production',
            'html_body' => '<p>Deployment <code>web-92f</code> failed health checks.</p>',
            'text_body' => 'Deployment web-92f failed health checks.',
            'sent_at' => now()->subDay(),
        ]);

        Message::query()->create([
            'organization_id' => $org->id,
            'direction' => 'outbound',
            'status' => 'suppressed',
            'provider' => 'resend',
            'from_email' => 'onboarding@acme.com',
            'from_name' => 'Acme Onboarding',
            'to' => [['email' => 'lea@brightpath.app']],
            'subject' => 'Finish setting up your domain',
            'html_body' => '<p>Your domain still needs DKIM.</p>',
            'text_body' => 'Your domain still needs DKIM.',
            'sent_at' => now()->subDays(2),
        ]);

        $thread = Thread::query()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mailbox->id,
            'subject' => 'Question about API rate limits',
            'snippet' => 'Hi team — we are hitting 429s on /emails during peak…',
            'last_message_at' => now()->subDays(2),
            'message_count' => 2,
            'is_read' => false,
        ]);

        Message::query()->create([
            'organization_id' => $org->id,
            'thread_id' => $thread->id,
            'mailbox_id' => $mailbox->id,
            'direction' => 'inbound',
            'status' => 'received',
            'from_email' => 'jordan@client.com',
            'from_name' => 'Jordan Lee',
            'to' => [['email' => 'support@acme.com']],
            'subject' => 'Question about API rate limits',
            'html_body' => '<p>Hi team — we are hitting 429s on /emails during peak.</p>',
            'text_body' => 'Hi team — we are hitting 429s on /emails during peak.',
            'received_at' => now()->subDays(2)->subHour(),
        ]);

        Message::query()->create([
            'organization_id' => $org->id,
            'thread_id' => $thread->id,
            'mailbox_id' => $mailbox->id,
            'direction' => 'outbound',
            'status' => 'delivered',
            'provider' => 'resend',
            'from_email' => 'support@acme.com',
            'from_name' => 'Acme Support',
            'to' => [['email' => 'jordan@client.com']],
            'subject' => 'Re: Question about API rate limits',
            'html_body' => '<p>Thanks for the report — we raised the burst limit for your key.</p>',
            'text_body' => 'Thanks for the report — we raised the burst limit for your key.',
            'sent_at' => now()->subDays(2),
        ]);

        $partner = Thread::query()->create([
            'organization_id' => $org->id,
            'mailbox_id' => $mailbox->id,
            'subject' => 'Partnership proposal',
            'snippet' => 'Would love to explore integrating MailDesk into…',
            'last_message_at' => now()->subDays(3),
            'message_count' => 1,
            'is_read' => false,
        ]);

        Message::query()->create([
            'organization_id' => $org->id,
            'thread_id' => $partner->id,
            'mailbox_id' => $mailbox->id,
            'direction' => 'inbound',
            'status' => 'received',
            'from_email' => 'ceo@partner.dev',
            'from_name' => 'Partner CEO',
            'to' => [['email' => 'support@acme.com']],
            'subject' => 'Partnership proposal',
            'html_body' => '<p>Would love to explore integrating MailDesk into our product suite.</p>',
            'text_body' => 'Would love to explore integrating MailDesk into our product suite.',
            'received_at' => now()->subDays(3),
        ]);

        if (! $org->webhooks()->exists()) {
            $hook = Webhook::query()->create([
                'organization_id' => $org->id,
                'url' => 'https://api.acme.com/hooks/mail',
                'secret' => Webhook::generateSecret(),
                'events' => ['email.sent', 'email.delivered', 'email.bounced'],
                'is_active' => true,
            ]);
            WebhookDelivery::query()->create([
                'webhook_id' => $hook->id,
                'event' => 'email.delivered',
                'payload' => ['subject' => 'Reset your password'],
                'response_status' => 200,
                'response_body' => 'ok',
                'status' => 'success',
                'attempts' => 1,
                'delivered_at' => now()->subMinutes(12),
            ]);
            Webhook::query()->create([
                'organization_id' => $org->id,
                'url' => 'https://staging.acme.com/webhooks/mail',
                'secret' => Webhook::generateSecret(),
                'events' => ['email.received'],
                'is_active' => false,
            ]);
        }
    }

    private function seedProductFeatures(Organization $org): void
    {
        if (! $org->suppressions()->exists()) {
            Suppression::query()->create([
                'organization_id' => $org->id,
                'email' => 'lea@brightpath.app',
                'reason' => 'Manual suppression',
                'source' => 'manual',
            ]);
            Suppression::query()->create([
                'organization_id' => $org->id,
                'email' => 'invalid@mail.invalid',
                'reason' => 'Hard bounce',
                'source' => 'bounce',
            ]);
        }

        if (! $org->templates()->exists()) {
            Template::query()->create([
                'organization_id' => $org->id,
                'name' => 'Welcome series · Day 1',
                'subject' => 'Welcome to Acme',
                'html' => '<p>Welcome aboard!</p>',
            ]);
            Template::query()->create([
                'organization_id' => $org->id,
                'name' => 'Password reset',
                'subject' => 'Reset your password',
                'html' => '<p>Click to reset.</p>',
            ]);
        }

        if (! $org->broadcasts()->exists()) {
            Broadcast::query()->create([
                'organization_id' => $org->id,
                'name' => 'March product update',
                'subject' => 'What is new in March',
                'html' => '<p>Product updates…</p>',
                'status' => 'sent',
                'sent_at' => now()->subDays(2),
            ]);
            Broadcast::query()->create([
                'organization_id' => $org->id,
                'name' => 'Billing notice draft',
                'subject' => 'Billing notice',
                'html' => '<p>Draft…</p>',
                'status' => 'draft',
            ]);
        }

        if (! $org->contacts()->exists()) {
            $contact = Contact::query()->create([
                'organization_id' => $org->id,
                'email' => 'jordan@client.com',
                'first_name' => 'Jordan',
                'last_name' => 'Lee',
                'meta' => ['status' => 'subscribed'],
            ]);
            Contact::query()->create([
                'organization_id' => $org->id,
                'email' => 'ceo@partner.dev',
                'first_name' => 'Partner',
                'last_name' => 'CEO',
                'meta' => ['status' => 'subscribed'],
            ]);

            $segment = Segment::query()->create([
                'organization_id' => $org->id,
                'name' => 'Product updates',
                'description' => 'Engaged product users',
            ]);
            $segment->contacts()->attach($contact->id);
        }

        if (! $org->automations()->exists()) {
            $automation = Automation::query()->create([
                'organization_id' => $org->id,
                'name' => 'Welcome sequence',
                'status' => 'active',
                'trigger' => 'user.created',
            ]);
            AutomationStep::query()->create([
                'automation_id' => $automation->id,
                'position' => 0,
                'type' => 'email',
                'config' => ['label' => 'Send Welcome series · Day 1'],
            ]);
            AutomationStep::query()->create([
                'automation_id' => $automation->id,
                'position' => 1,
                'type' => 'delay',
                'config' => ['label' => 'Wait 2 days'],
            ]);
        }
    }

    private function seedMailboxUsers(Organization $org): void
    {
        $domain = $org->domains()->where('name', 'acme.com')->first();

        if (! $domain) {
            return;
        }

        $defaults = [
            [
                'email' => 'support@acme.com',
                'display_name' => 'Acme Support',
                'role' => 'admin',
                'status' => 'active',
                'inbox' => true,
                'transactional' => true,
                'marketing' => false,
                'send_limit' => 5000,
            ],
            [
                'email' => 'hello@acme.com',
                'display_name' => 'Acme Hello',
                'role' => 'staff',
                'status' => 'active',
                'inbox' => true,
                'transactional' => false,
                'marketing' => true,
                'send_limit' => 2000,
            ],
            [
                'email' => 'dev@acme.com',
                'display_name' => 'Acme Developers',
                'role' => 'developer',
                'status' => 'active',
                'inbox' => false,
                'transactional' => true,
                'marketing' => false,
                'send_limit' => 10000,
            ],
            [
                'email' => 'billing@acme.com',
                'display_name' => 'Acme Billing',
                'role' => 'staff',
                'status' => 'inactive',
                'inbox' => true,
                'transactional' => true,
                'marketing' => false,
                'send_limit' => 1000,
            ],
        ];

        foreach ($defaults as $row) {
            Mailbox::query()->updateOrCreate(
                [
                    'organization_id' => $org->id,
                    'email' => $row['email'],
                ],
                array_merge($row, [
                    'domain_id' => $domain->id,
                    'type' => 'shared',
                ]),
            );
        }
    }
}
