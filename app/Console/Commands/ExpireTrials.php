<?php

namespace App\Console\Commands;

use App\Services\TrialService;
use Illuminate\Console\Command;

class ExpireTrials extends Command
{
    protected $signature = 'billing:expire-trials';

    protected $description = 'Mark expired free-trial workspaces as past_due (Settings/Billing lockout)';

    public function handle(TrialService $trials): int
    {
        $count = $trials->expireDue();
        $this->info($count === 0
            ? 'No trials to expire.'
            : "Marked {$count} workspace(s) past_due.");

        return self::SUCCESS;
    }
}
