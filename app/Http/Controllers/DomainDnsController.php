<?php

namespace App\Http\Controllers;

use App\Models\DnsConnection;
use App\Models\Domain;
use App\Services\Dns\CloudflareDnsProvider;
use App\Services\Dns\DnsProviderException;
use App\Services\Dns\DnsRecordManager;
use App\Services\Domains\DomainVerifier;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lets a client manage their domain's mail DNS records at their DNS host
 * (Cloudflare for now) straight from the Verify DNS page.
 */
class DomainDnsController extends Controller
{
    public function __construct(
        private readonly DnsRecordManager $manager,
        private readonly DomainVerifier $verifier,
    ) {}

    public function connect(Request $request, Domain $domain): RedirectResponse
    {
        $organization = $this->authorizeDomain($request, $domain);

        $validated = $request->validate([
            'provider' => ['required', Rule::in(['cloudflare'])],
            'api_token' => ['required', 'string', 'min:20', 'max:255'],
        ]);

        try {
            $zone = (new CloudflareDnsProvider(trim($validated['api_token'])))->findZone($domain->name);
        } catch (DnsProviderException $e) {
            return back()->withErrors(['api_token' => $e->getMessage()]);
        }

        DnsConnection::updateOrCreate(
            ['domain_id' => $domain->id],
            [
                'organization_id' => $organization->id,
                'provider' => $validated['provider'],
                'credentials' => ['api_token' => trim($validated['api_token'])],
                'zone_id' => $zone['id'],
                'zone_name' => $zone['name'],
            ],
        );

        // Publish everything the domain needs straight away, then re-check.
        $this->verifier->verify($domain->fresh());
        $publish = $domain->fresh()->dns_records['auto_publish'] ?? null;

        if (! empty($publish['error'])) {
            return back()->with('error', "Connected to Cloudflare zone {$zone['name']}, but publishing records failed: {$publish['error']}");
        }

        $changed = (int) ($publish['created'] ?? 0) + (int) ($publish['updated'] ?? 0);

        return back()->with('success', $changed > 0
            ? "Connected to Cloudflare zone {$zone['name']} and published {$changed} record".($changed > 1 ? 's' : '').'.'
            : "Connected to Cloudflare zone {$zone['name']}. All records were already published.");
    }

    public function disconnect(Request $request, Domain $domain): RedirectResponse
    {
        $this->authorizeDomain($request, $domain);
        $domain->dnsConnection()->delete();

        return back()->with('success', 'DNS provider disconnected. Your records at the provider were not changed.');
    }

    public function apply(Request $request, Domain $domain): RedirectResponse
    {
        $this->authorizeDomain($request, $domain);
        $validated = $request->validate(['key' => ['nullable', 'string', 'max:50']]);

        return $this->run($domain, function ($connection) use ($domain, $validated) {
            $counts = $this->manager->apply($domain, $connection, $validated['key'] ?? null);

            $message = $counts['created'] + $counts['updated'] === 0
                ? 'Nothing to change. Those records are already published.'
                : "Published at Cloudflare: {$counts['created']} added, {$counts['updated']} updated.";

            if ($counts['skipped_inbound_mx'] ?? false) {
                $message .= " Note: Receiving MX was skipped because {$domain->name} already has MX records. Use 'Enable receiving' to route all email to MailDesk.";
            }

            return $message;
        });
    }

    /**
     * Explicitly enable receiving for a domain by publishing the inbound MX record,
     * even if the domain already has existing MX records. This requires user confirmation
     * since it may affect existing email service.
     */
    public function enableReceiving(Request $request, Domain $domain): RedirectResponse
    {
        $this->authorizeDomain($request, $domain);
        $request->validate(['confirm' => ['sometimes', 'accepted']]);

        $connection = $domain->dnsConnection;

        if ($connection === null) {
            return back()->with('error', 'Connect a DNS provider first to enable receiving.');
        }

        // Check if there's an inbound_mx record to publish
        $dns = $domain->normalizedDnsRecords();
        $inboundMx = collect($dns['records'] ?? [])->firstWhere('key', 'inbound_mx');

        if ($inboundMx === null) {
            return back()->with('error', 'No receiving MX record is configured for this domain. Re-verify the domain to fetch receiving records from Resend.');
        }

        // Check for existing MX and require confirmation
        $existingMx = $this->manager->findExistingRootMx($domain, $connection);
        if ($existingMx !== [] && ! $request->boolean('confirm')) {
            $providers = implode(', ', array_column($existingMx, 'content'));

            return back()->with('receiving_confirmation', [
                'required' => true,
                'existing_mx' => $providers,
                'message' => "{$domain->name} already has MX records ({$providers}). Adding MailDesk's receiving MX may change where email is delivered, depending on MX priorities.",
            ]);
        }

        return $this->run($domain, function ($connection) use ($domain) {
            $counts = $this->manager->apply($domain, $connection, 'inbound_mx', forceInboundMx: true);

            return $counts['created'] > 0
                ? "Receiving enabled! The MX record for {$domain->name} was published alongside any existing MX records. Email delivery may now be affected depending on MX priorities."
                : "Receiving MX record is already published for {$domain->name}.";
        });
    }

    public function update(Request $request, Domain $domain, string $record): RedirectResponse
    {
        $this->authorizeDomain($request, $domain);
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:4096'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        return $this->run($domain, function ($connection) use ($domain, $record, $validated) {
            $this->manager->updateRecord($domain, $connection, $record, $validated);

            return 'Record updated at Cloudflare.';
        });
    }

    public function destroy(Request $request, Domain $domain, string $record): RedirectResponse
    {
        $this->authorizeDomain($request, $domain);

        return $this->run($domain, function ($connection) use ($domain, $record) {
            $this->manager->deleteRecord($domain, $connection, $record);

            return 'Record deleted at Cloudflare.';
        });
    }

    private function run(Domain $domain, callable $action): RedirectResponse
    {
        $connection = $domain->dnsConnection;

        if ($connection === null) {
            return back()->with('error', 'Connect a DNS provider first.');
        }

        try {
            $message = $action($connection);
        } catch (DnsProviderException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Re-check right away; public DNS may still lag a few minutes behind.
        $this->verifier->verify($domain->fresh());

        return back()->with('success', $message);
    }

    private function authorizeDomain(Request $request, Domain $domain)
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($domain->organization_id === $organization->id, 404);

        return $organization;
    }
}
