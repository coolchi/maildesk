<?php

namespace App\Console\Commands;

use App\Services\Imap\ImapSyncService;
use Illuminate\Console\Command;

class SyncImapMailboxesCommand extends Command
{
    protected $signature = 'mail:sync-imap';

    protected $description = 'Pull new IMAP messages from Stalwart into MailDesk inboxes';

    public function handle(ImapSyncService $sync): int
    {
        $result = $sync->syncAll();
        $this->info(sprintf(
            'IMAP sync: %d message(s) from %d mailbox(es), %d error(s).',
            $result['synced'],
            $result['mailboxes'],
            $result['errors'],
        ));

        return $result['errors'] > 0 && $result['synced'] === 0 ? self::FAILURE : self::SUCCESS;
    }
}
