<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\Dns\DnsProviderException;
use App\Services\Dns\DnsRecordManager;
use App\Services\Domains\DomainVerifier;
use Illuminate\Console\Command;
use Throwable;

class EnableDomainReceiving extends Command
{
    protected $signature = 'domains:enable-receiving
        {--domain= : Enable receiving for a specific domain name}
        {--all : Enable receiving for all verified domains that have it configured}
        {--dry-run : Show what would be done without making changes}
        {--force : Publish the MX even if the domain has existing MX records}';

    protected $description = 'Enable receiving for domains by publishing the inbound MX record';

    public function handle(DomainVerifier $verifier, DnsRecordManager $manager): int
    {
        $query = Domain::query()
            ->with(['organization', 'dnsConnection'])
            ->where('status', 'verified');

        if ($name = $this->option('domain')) {
            $query->where('name', strtolower((string) $name));
        } elseif (! $this->option('all')) {
            $this->error('Please specify --domain=<name> or --all to process multiple domains.');

            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $processed = 0;
        $enabled = 0;
        $skipped = 0;

        $query->orderBy('id')->each(function (Domain $domain) use ($verifier, $manager, $dryRun, $force, &$processed, &$enabled, &$skipped) {
            $processed++;
            $dns = $domain->normalizedDnsRecords();
            $inboundMx = collect($dns['records'] ?? [])->firstWhere('key', 'inbound_mx');

            if ($inboundMx === null) {
                $this->warn("{$domain->name}: No inbound_mx record configured. Re-verify to fetch receiving records from Resend.");
                $skipped++;

                return;
            }

            $connection = $domain->dnsConnection;

            if ($connection === null) {
                $this->info("{$domain->name}: No DNS provider connected. Receiving MX record: {$inboundMx['name']} -> {$inboundMx['value']} (priority {$inboundMx['priority']})");
                $this->line('  Add this MX record manually to enable receiving.');
                $skipped++;

                return;
            }

            try {
                $existingMx = $manager->findExistingRootMx($domain, $connection);
            } catch (DnsProviderException $e) {
                $this->error("{$domain->name}: Could not check existing MX records: {$e->getMessage()}");
                $skipped++;

                return;
            }

            if ($existingMx !== [] && ! $force) {
                $providers = implode(', ', array_map(fn ($r) => $r['content'], $existingMx));
                $this->warn("{$domain->name}: Has existing MX records ({$providers}). Use --force to override.");
                $skipped++;

                return;
            }

            if ($dryRun) {
                $action = $existingMx !== [] ? 'WOULD ADD (existing MX records will be preserved)' : 'WOULD ADD';
                $this->info("{$domain->name}: {$action} MX record {$inboundMx['value']} (priority {$inboundMx['priority']})");
                $enabled++;

                return;
            }

            try {
                $counts = $manager->apply($domain, $connection, 'inbound_mx');

                if ($counts['created'] > 0) {
                    $this->info("{$domain->name}: Receiving MX record published.");
                    $enabled++;
                } else {
                    $this->line("{$domain->name}: Receiving MX record already exists.");
                }

                $verifier->verify($domain->fresh());
            } catch (Throwable $e) {
                report($e);
                $this->error("{$domain->name}: Failed to publish: {$e->getMessage()}");
                $skipped++;
            }
        });

        $this->newLine();
        $this->info("Processed {$processed} domain(s): {$enabled} enabled, {$skipped} skipped.");

        if ($dryRun) {
            $this->warn('This was a dry run. No changes were made.');
        }

        return self::SUCCESS;
    }
}
