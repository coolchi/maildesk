<?php

namespace App\Services\Imap;

use App\Models\Mailbox;
use App\Models\Organization;
use App\Services\WorkspaceAccess;
use Illuminate\Http\Request;

class MailClientSettings
{
    public function __construct(
        protected StalwartClient $client,
        protected WorkspaceAccess $access,
    ) {}

    /**
     * Props for Settings → Mail clients (no secrets except one-time flash).
     *
     * @return array<string, mixed>
     */
    public function for(Request $request, Organization $organization): array
    {
        $canManage = $this->access->isTeam($request->user(), $organization);
        $clients = config('maildesk.stalwart.clients', []);

        $mailboxes = $organization->mailboxes()
            ->orderBy('email')
            ->get()
            ->map(fn (Mailbox $mailbox) => [
                'id' => $mailbox->id,
                'email' => $mailbox->email,
                'display_name' => $mailbox->display_name,
                'imap_enabled' => (bool) $mailbox->imap_enabled,
                'has_app_password' => filled($mailbox->imap_password),
                'stalwart_account_id' => $mailbox->stalwart_account_id,
            ])
            ->values()
            ->all();

        return [
            'enabled' => $this->client->enabled(),
            'can_manage' => $canManage,
            'server' => [
                'imap' => $clients['imap'] ?? [],
                'smtp' => $clients['smtp'] ?? [],
                'pop' => $clients['pop'] ?? [],
            ],
            'mailboxes' => $mailboxes,
            'docs' => [
                'gmail' => 'Add account → Other (IMAP). Use the app password from MailDesk, not your web login.',
                'apple' => 'Mail → Add Account → Other → IMAP with the host/ports below.',
                'outlook' => 'IMAP account type; encryption SSL/TLS on 993 and 465.',
            ],
        ];
    }
}
