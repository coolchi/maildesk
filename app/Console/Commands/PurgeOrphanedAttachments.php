<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class PurgeOrphanedAttachments extends Command
{
    protected $signature = 'attachments:purge-tmp
        {--hours=24 : Delete files older than this many hours}
        {--dry-run : List matching files without deleting}';

    protected $description = 'Delete orphaned files from attachments/tmp/ older than the retention period';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $hours = (int) ($this->option('hours') ?? 24);
        $hours = max(1, $hours);
        $cutoff = Carbon::now()->subHours($hours);

        $tmpDir = 'attachments/tmp';

        if (! $disk->exists($tmpDir)) {
            $this->info('No attachments/tmp directory found.');

            return self::SUCCESS;
        }

        $directories = $disk->directories($tmpDir);
        $deleted = 0;
        $skipped = 0;

        foreach ($directories as $orgDir) {
            $files = $disk->files($orgDir);

            foreach ($files as $file) {
                $lastModified = Carbon::createFromTimestamp($disk->lastModified($file));

                if ($lastModified->greaterThan($cutoff)) {
                    $skipped++;

                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line("Would delete: {$file} (modified {$lastModified->diffForHumans()})");
                    $deleted++;

                    continue;
                }

                $disk->delete($file);
                $deleted++;
            }
        }

        if ($this->option('dry-run')) {
            $this->info("Would delete {$deleted} file(s) older than {$hours} hour(s). {$skipped} file(s) would be kept.");

            return self::SUCCESS;
        }

        $this->info("Deleted {$deleted} orphaned attachment(s) older than {$hours} hour(s). {$skipped} recent file(s) kept.");

        return self::SUCCESS;
    }
}
