<?php

namespace App\Console\Commands;

use App\Jobs\PushInboundToStalwart;
use App\Jobs\PushOutboundToStalwart;
use App\Models\Mailbox;
use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PushToStalwartCommand extends Command
{
    protected $signature = 'mail:push-to-stalwart
        {--mailbox= : Only push messages for this mailbox email}
        {--since= : Only push messages received/sent since this date (Y-m-d or relative like "-7 days")}
        {--direction=both : Which messages to push: inbound, outbound, or both}
        {--max-attempts=3 : Skip messages that have already failed this many times}
        {--dry-run : Show what would be pushed without actually dispatching jobs}';

    protected $description = 'Backfill messages to Stalwart IMAP mailboxes (inbound to INBOX, outbound to Sent)';

    public function handle(): int
    {
        if (! config('maildesk.stalwart.enabled')) {
            $this->error('Stalwart is not enabled. Set STALWART_ENABLED=true in your environment.');

            return self::FAILURE;
        }

        $mailboxEmail = $this->option('mailbox');
        $since = $this->parseSinceDate($this->option('since'));
        $dryRun = (bool) $this->option('dry-run');
        $direction = $this->option('direction');
        $maxAttempts = (int) $this->option('max-attempts');

        if (! in_array($direction, ['inbound', 'outbound', 'both'], true)) {
            $this->error('Invalid direction. Use: inbound, outbound, or both');

            return self::FAILURE;
        }

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

        $totalInboundDispatched = 0;
        $totalOutboundDispatched = 0;
        $totalSkipped = 0;

        foreach ($mailboxes as $mailbox) {
            /** @var Mailbox $mailbox */
            $this->line('');
            $this->info("Processing mailbox: {$mailbox->email}");

            if ($direction === 'inbound' || $direction === 'both') {
                [$dispatched, $skipped] = $this->processInbound($mailbox, $since, $dryRun, $maxAttempts);
                $totalInboundDispatched += $dispatched;
                $totalSkipped += $skipped;
            }

            if ($direction === 'outbound' || $direction === 'both') {
                [$dispatched, $skipped] = $this->processOutbound($mailbox, $since, $dryRun, $maxAttempts);
                $totalOutboundDispatched += $dispatched;
                $totalSkipped += $skipped;
            }
        }

        $this->line('');
        $prefix = $dryRun ? '[dry-run] Would dispatch' : 'Total dispatched';

        if ($direction === 'both') {
            $this->info("{$prefix}: {$totalInboundDispatched} inbound, {$totalOutboundDispatched} outbound, skipped: {$totalSkipped}");
        } elseif ($direction === 'inbound') {
            $this->info("{$prefix}: {$totalInboundDispatched} inbound job(s), skipped: {$totalSkipped}");
        } else {
            $this->info("{$prefix}: {$totalOutboundDispatched} outbound job(s), skipped: {$totalSkipped}");
        }

        $total = $totalInboundDispatched + $totalOutboundDispatched;
        if ($total > 0 && ! $dryRun) {
            $this->line('');
            $this->comment('Jobs have been dispatched to the queue. Run your queue worker to process them.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int} [dispatched, skipped]
     */
    protected function processInbound(Mailbox $mailbox, ?Carbon $since, bool $dryRun, int $maxAttempts): array
    {
        $query = Message::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('direction', 'inbound')
            ->whereNull('stalwart_pushed_at')
            ->where('provider', '!=', 'stalwart-imap')
            ->where('stalwart_push_attempts', '<', $maxAttempts);

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
                    $this->line("  Skipped inbound {$message->uuid} (no body)");
                }

                continue;
            }

            if ($dryRun) {
                $this->line("  [dry-run] Would dispatch inbound: {$message->uuid} ({$message->subject})");
                $dispatched++;

                continue;
            }

            PushInboundToStalwart::dispatch($message->id);
            $dispatched++;

            if ($this->output->isVerbose()) {
                $this->line("  Dispatched inbound: {$message->uuid}");
            }
        }

        $this->info("  Inbound - Dispatched: {$dispatched}, Skipped: {$skipped}");

        return [$dispatched, $skipped];
    }

    /**
     * @return array{0: int, 1: int} [dispatched, skipped]
     */
    protected function processOutbound(Mailbox $mailbox, ?Carbon $since, bool $dryRun, int $maxAttempts): array
    {
        $query = Message::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('direction', 'outbound')
            ->whereNull('stalwart_pushed_at')
            ->whereIn('status', ['sent', 'delivered'])
            ->where('stalwart_push_attempts', '<', $maxAttempts);

        if ($since !== null) {
            $query->where(function ($q) use ($since) {
                $q->where('sent_at', '>=', $since)
                    ->orWhere(function ($inner) use ($since) {
                        $inner->whereNull('sent_at')
                            ->where('created_at', '>=', $since);
                    });
            });
        }

        $messages = $query->orderBy('sent_at')->get();

        $this->line("  Found {$messages->count()} unpushed outbound message(s).");

        $dispatched = 0;
        $skipped = 0;

        foreach ($messages as $message) {
            /** @var Message $message */
            if (blank($message->text_body) && blank($message->html_body)) {
                $skipped++;
                if ($this->output->isVerbose()) {
                    $this->line("  Skipped outbound {$message->uuid} (no body)");
                }

                continue;
            }

            if ($dryRun) {
                $this->line("  [dry-run] Would dispatch outbound: {$message->uuid} ({$message->subject})");
                $dispatched++;

                continue;
            }

            PushOutboundToStalwart::dispatch($message->id);
            $dispatched++;

            if ($this->output->isVerbose()) {
                $this->line("  Dispatched outbound: {$message->uuid}");
            }
        }

        $this->info("  Outbound - Dispatched: {$dispatched}, Skipped: {$skipped}");

        return [$dispatched, $skipped];
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
