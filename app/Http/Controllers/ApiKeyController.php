<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiKeyController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = CurrentOrganization::from($request);

        $keys = $organization->apiKeys()
            ->latest()
            ->get()
            ->map(fn (ApiKey $key) => $key->toWorkspaceArray())
            ->values()
            ->all();

        $domains = $organization->domains()
            ->orderBy('name')
            ->pluck('name')
            ->prepend('All domains')
            ->values()
            ->all();

        return Inertia::render('ApiKeys/Index', [
            'keys' => $keys,
            'domainOptions' => $domains,
            'plainApiKey' => $request->session()->get('plain_api_key'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'permission' => ['required', 'string', 'in:Full access,Sending access'],
            'domain' => ['nullable', 'string', 'max:255'],
        ]);

        $abilities = $validated['permission'] === 'Sending access'
            ? ['emails:send']
            : ['*'];

        if (($validated['domain'] ?? 'All domains') !== 'All domains') {
            $abilities[] = 'domain:'.$validated['domain'];
        }

        $issued = ApiKey::issue(
            $organization,
            $validated['name'],
            $request->user(),
            $abilities,
        );

        return redirect()
            ->route('api-keys')
            ->with('plain_api_key', $issued['plain'])
            ->with('success', 'API key created.');
    }

    public function update(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($apiKey->organization_id === $organization->id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'permission' => ['required', 'string', 'in:Full access,Sending access'],
            'domain' => ['nullable', 'string', 'max:255'],
        ]);

        $abilities = $validated['permission'] === 'Sending access'
            ? ['emails:send']
            : ['*'];

        if (($validated['domain'] ?? 'All domains') !== 'All domains') {
            $abilities[] = 'domain:'.$validated['domain'];
        }

        $apiKey->update([
            'name' => $validated['name'],
            'abilities' => $abilities,
        ]);

        return redirect()
            ->route('api-keys')
            ->with('success', 'API key updated.');
    }

    public function destroy(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($apiKey->organization_id === $organization->id, 404);

        $name = $apiKey->name;
        $apiKey->delete();

        return redirect()
            ->route('api-keys')
            ->with('success', "Deleted “{$name}”.");
    }
}
