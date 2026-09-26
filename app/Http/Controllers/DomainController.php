<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Organization;
use App\Services\Dns\DnsProviderException;
use App\Services\Dns\DnsRecordManager;
use App\Services\Domains\DomainVerifier;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DomainController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = CurrentOrganization::from($request);

        $domains = $organization->domains()
            ->latest()
            ->get()
            ->map(fn (Domain $domain) => $domain->toWorkspaceArray($organization->region))
            ->values()
            ->all();

        return Inertia::render('Domains/Index', [
            'domains' => $domains,
        ]);
    }

    public function show(Request $request, Domain $domain, DnsRecordManager $dns): Response
    {
        $organization = CurrentOrganization::from($request);
        $this->ensureDomainBelongsToOrganization($domain, $organization);

        return Inertia::render('Domains/Show', [
            'domain' => $domain->toWorkspaceArray($organization->region),
            'dns' => $this->dnsState($domain, $dns),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dnsState(Domain $domain, DnsRecordManager $dns): array
    {
        $connection = $domain->dnsConnection;

        if ($connection === null) {
            return ['connection' => null, 'records' => [], 'live' => [], 'error' => null];
        }

        try {
            $plan = $dns->plan($domain, $connection);

            return [
                'connection' => $connection->toWorkspaceArray(),
                ...$plan,
                'error' => null,
                'auto_publish' => $domain->dns_records['auto_publish'] ?? null,
            ];
        } catch (DnsProviderException $e) {
            return ['connection' => $connection->toWorkspaceArray(), 'records' => [], 'live' => [], 'error' => $e->getMessage()];
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i',
                Rule::unique('domains', 'name')->where(
                    fn ($query) => $query->where('organization_id', $organization->id),
                ),
            ],
        ]);

        $name = strtolower($validated['name']);

        $domain = Domain::query()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'status' => 'pending',
            'provider' => $organization->default_provider,
            'dns_records' => Domain::defaultDnsRecords($name),
        ]);

        return redirect()
            ->route('domains.show', $domain)
            ->with('success', 'Domain added. Configure DNS next.');
    }

    public function destroy(Request $request, Domain $domain): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        $this->ensureDomainBelongsToOrganization($domain, $organization);

        $domain->delete();

        return redirect()
            ->route('domains')
            ->with('success', 'Domain deleted.');
    }

    public function verify(Request $request, Domain $domain, DomainVerifier $verifier): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        $this->ensureDomainBelongsToOrganization($domain, $organization);

        $result = $verifier->verify($domain);

        if ($result['verified']) {
            $message = "{$domain->name} verified.";

            if ($result['warnings'] !== []) {
                $message .= ' Warning: '.implode(' ', $result['warnings']);
            }

            return back()->with('success', $message);
        }

        $failing = strtoupper(implode(', ', $result['failing']));
        $message = "{$domain->name} is not verified yet. Not detected in DNS: {$failing}. DNS can take a while to propagate; re-check later.";

        if ($result['provider_error']) {
            $message .= ' Resend registration failed: '.$result['provider_error'];
        }

        return back()->with('error', $message);
    }

    private function ensureDomainBelongsToOrganization(Domain $domain, Organization $organization): void
    {
        abort_unless($domain->organization_id === $organization->id, 404);
    }
}
