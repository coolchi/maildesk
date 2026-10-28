<?php

namespace App\Console\Commands;

use App\Models\Message;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mail:repair-references {--dry-run : Show what would be repaired without making changes}')]
#[Description('Repair corrupted References headers in messages (e.g., JSON wrapped in angle brackets)')]
class RepairMessageReferences extends Command
{
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $repaired = 0;
        $checked = 0;

        $this->info($dryRun ? 'Dry run - no changes will be made.' : 'Repairing corrupted references...');

        Message::query()
            ->whereNotNull('references')
            ->chunkById(500, function ($messages) use ($dryRun, &$repaired, &$checked) {
                foreach ($messages as $message) {
                    $checked++;
                    $original = $message->references;

                    if (! is_array($original) || $original === []) {
                        continue;
                    }

                    $needsRepair = false;
                    foreach ($original as $ref) {
                        if (! is_string($ref)) {
                            continue;
                        }
                        if (str_starts_with($ref, '<[') || str_starts_with($ref, '["') || preg_match('/^<\[.*\]>$/', $ref)) {
                            $needsRepair = true;
                            break;
                        }
                    }

                    if (! $needsRepair) {
                        continue;
                    }

                    $fixed = $this->parseMessageIds($original);

                    if ($fixed === $original) {
                        continue;
                    }

                    $repaired++;
                    $this->line("Message #{$message->id}: "
                        .json_encode($original)
                        .' -> '
                        .json_encode($fixed));

                    if (! $dryRun) {
                        $message->forceFill(['references' => $fixed ?: null])->save();
                    }
                }
            });

        $this->info("Checked {$checked} messages with references.");
        $this->info($dryRun
            ? "Would repair {$repaired} messages."
            : "Repaired {$repaired} messages.");

        return self::SUCCESS;
    }

    /**
     * Parse message IDs from various stored formats, handling corrupted data.
     *
     * @param  mixed  $value
     * @return array<string>
     */
    protected function parseMessageIds($value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $this->parseMessageIds($decoded);
            }

            if (preg_match_all('/<([^<>]+)>/', $value, $matches)) {
                $extracted = [];
                foreach ($matches[1] as $inner) {
                    $innerDecoded = json_decode($inner, true);
                    if (is_array($innerDecoded)) {
                        $extracted = array_merge($extracted, $this->parseMessageIds($innerDecoded));
                    } elseif (str_contains($inner, '@')) {
                        $extracted[] = '<'.trim($inner).'>';
                    }
                }
                if ($extracted !== []) {
                    return array_values(array_unique($extracted));
                }
            }

            if (str_contains($value, ' ')) {
                $value = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY);
            } else {
                $value = [$value];
            }
        }

        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $id) {
            if (is_array($id)) {
                $result = array_merge($result, $this->parseMessageIds($id));

                continue;
            }

            if (! is_string($id) || $id === '') {
                continue;
            }

            $decoded = json_decode($id, true);
            if (is_array($decoded)) {
                $result = array_merge($result, $this->parseMessageIds($decoded));

                continue;
            }

            if (preg_match('/^<(\[.+\])>$/', $id, $outerMatch)) {
                $innerDecoded = json_decode($outerMatch[1], true);
                if (is_array($innerDecoded)) {
                    $result = array_merge($result, $this->parseMessageIds($innerDecoded));

                    continue;
                }
            }

            $bare = trim($id, '<> ');
            if ($bare === '' || ! str_contains($bare, '@')) {
                continue;
            }

            $result[] = '<'.$bare.'>';
        }

        return array_values(array_unique($result));
    }
}
