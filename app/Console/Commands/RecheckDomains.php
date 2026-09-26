<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\Domains\DomainVerifier;
use Illuminate\Console\Command;
use Throwable;

class RecheckDomains extends Command
{
    protected $signature = 'domains:recheck
        {--domain= : Only re-check this domain name}
        {--all : Include domains that are already verified}';

    protected $description = 'Re-check sending domains against DNS (and Resend) and update their status';

    public function handle(DomainVerifier $verifier): int
    {
        $query = Domain::query()->with('organization');

        if ($name = $this->option('domain')) {
            $query->where('name', strtolower((string) $name));
        } elseif (! $this->option('all')) {
            $query->where('status', '!=', 'verified');
        }

        $count = 0;

        $query->orderBy('id')->each(function (Domain $domain) use ($verifier, &$count) {
            $count++;

            try {
                $result = $verifier->verify($domain);
            } catch (Throwable $e) {
                report($e);
                $this->error("{$domain->name}: {$e->getMessage()}");

                return;
            }

            $summary = collect($result['checks'])
                ->map(fn (bool $pass, string $key) => strtoupper($key).'='.($pass ? 'pass' : 'fail'))
                ->implode(' ');

            $this->line(sprintf('%s: %s (%s)', $domain->name, $result['verified'] ? 'verified' : 'not verified', $summary));
        });

        $this->info("Checked {$count} domain(s).");

        return self::SUCCESS;
    }
}
