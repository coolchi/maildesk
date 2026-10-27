<?php

namespace App\Console\Commands;

use App\Jobs\PushInboundToStalwart;
use App\Models\Mailbox;
use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PushToStalwartCommand extends Command
{
    protected $signature = 'mail:push-to-stalwart
        {--mailbox= : Only push messages for this mailbox email}
        {--since= : Only push messages received since this date (Y-m-d or relative like "-7 days")}
        {--dry-run : Show what would be pushed without actually dispatching jobs}';

    protected $description = 'Backfill inbound messages to Stalwart IMAP mailboxes';

    public function handle(): int
    {
        if (! config('maildesk.stalwart.enabled')) {
            $this->error('Stalwart is not enabled. Set STALWART_ENABLED=true in your environment.');

            return self::FAILURE;
        }

        $mailboxEmail = $this->option('mailbox');
        $since = $this->parseSinceDate($this->option('since'));
        $dryRun = (bool) $this->option('dry-run');

        $mailboxQuery = Mailbox::query()
            ->where('imap_enabled', true)
            ->whereNotNull('imap_password')
            ->where('status', 'active');

        if ($mailboxEmail) {
            $mailboxQuery->where('email', $mailboxEmail);
        }

        $mailboxes = $mailboxQuery->get();

        if ($mailboxes->isEmpty()) {
            $this->warn('No IMAP-enabled mailboxes found.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Found %d IMAP-enabled mailbox(es)%s.',
            $mailboxes->count(),
            $mailboxEmail ? " matching '{$mailboxEmail}'" : '',
        ));

        $totalDispatched = 0;
        $totalSkipped = 0;

        foreach ($mailboxes as $mailbox) {
            /** @var Mailbox $mailbox */
            $this->line('');
            $this->info("Processing mailbox: {$mailbox->email}");

            $query = Message::query()
                ->where('mailbox_id', $mailbox->id)
                ->where('direction', 'inbound')
                ->whereNull('stalwart_pushed_at');

            if ($since !== null) {
                $query->where('received_at', '>=', $since);
            }

            $messages = $query->orderBy('received_at')->get();

            $this->line("  Found {$messages->count()} unpushed inbound message(s).");

            $dispatched = 0;
            $skipped = 0;

            foreach ($messages as $message) {
                /** @var Message $message */
                if (blank($message->text_body) && blank($message->html_body)) {
                    $skipped++;
                    if ($this->output->isVerbose()) {
                        $this->line("  Skipped message {$message->uuid} (no body)");
                    }

                    continue;
                }

                if ($dryRun) {
                    $this->line("  [dry-run] Would dispatch: {$message->uuid} ({$message->subject})");
                    $dispatched++;

                    continue;
                }

                PushInboundToStalwart::dispatch($message->id);
                $dispatched++;

                if ($this->output->isVerbose()) {
                    $this->line("  Dispatched: {$message->uuid}");
                }
            }

            $this->info("  Dispatched: {$dispatched}, Skipped: {$skipped}");
            $totalDispatched += $dispatched;
            $totalSkipped += $skipped;
        }

        $this->line('');
        $prefix = $dryRun ? '[dry-run] Would dispatch' : 'Total dispatched';
        $this->info("{$prefix}: {$totalDispatched} job(s), skipped: {$totalSkipped}");

        if ($totalDispatched > 0 && ! $dryRun) {
            $this->line('');
            $this->comment('Jobs have been dispatched to the queue. Run your queue worker to process them.');
        }

        return self::SUCCESS;
    }

    protected function parseSinceDate(?string $since): ?Carbon
    {
        if ($since === null || $since === '') {
            return null;
        }

        if (preg_match('/^-?\d+\s+(day|week|month|year)s?$/i', $since)) {
            return Carbon::parse($since)->startOfDay();
        }

        return Carbon::parse($since)->startOfDay();
    }
}
