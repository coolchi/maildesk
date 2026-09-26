<?php

namespace App\Console\Commands;

use App\Models\Thread;
use App\Services\ThreadTrashService;
use Illuminate\Console\Command;

class PurgeTrashedThreads extends Command
{
    protected $signature = 'inbox:purge-trash
        {--days= : Retention days (defaults to config maildesk.trash.retention_days)}
        {--dry-run : List matching threads without deleting}';

    protected $description = 'Permanently delete conversations that have been in Trash longer than the retention period';

    public function handle(ThreadTrashService $trash): int
    {
        $days = (int) ($this->option('days') ?? config('maildesk.trash.retention_days', 30));
        $days = max(1, $days);
        $cutoff = now()->subDays($days);

        $query = Thread::query()
            ->where('is_trashed', true)
            ->whereNotNull('trashed_at')
            ->where('trashed_at', '<=', $cutoff)
            ->orderBy('id');

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info("No trashed conversations older than {$days} day(s).");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Would permanently delete {$total} conversation(s) trashed on or before {$cutoff->toDateTimeString()}.");

            return self::SUCCESS;
        }

        $deleted = 0;
        $query->with(['messages.attachments'])->chunkById(50, function ($threads) use ($trash, &$deleted) {
            $deleted += $trash->permanentlyDeleteMany($threads);
        });

        $this->info("Permanently deleted {$deleted} conversation(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
