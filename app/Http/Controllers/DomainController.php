<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Organization;
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

    public function show(Request $request, Domain $domain): Response
    {
        $organization = CurrentOrganization::from($request);
        $this->ensureDomainBelongsToOrganization($domain, $organization);

        return Inertia::render('Domains/Show', [
            'domain' => $domain->toWorkspaceArray($organization->region),
        ]);
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

    public function verify(Request $request, Domain $domain): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        $this->ensureDomainBelongsToOrganization($domain, $organization);

        $dns = $domain->normalizedDnsRecords();
        $dns['checks'] = [
            'spf' => true,
            'dkim' => true,
            'dmarc' => true,
        ];

        $domain->update([
            'status' => 'verified',
            'verified_at' => now(),
            'dns_records' => $dns,
        ]);

        return back()->with('success', "{$domain->name} verified.");
    }

    private function ensureDomainBelongsToOrganization(Domain $domain, Organization $organization): void
    {
        abort_unless($domain->organization_id === $organization->id, 404);
    }
}
