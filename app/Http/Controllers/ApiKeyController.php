<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            'expires_in_days' => ['nullable', 'integer', 'in:0,7,30,90,180,365'],
            'expires_at' => ['nullable', 'date', 'after:today', 'before:+5 years'],
        ]);

        $abilities = $validated['permission'] === 'Sending access'
            ? ['emails:send']
            : ['*'];

        if (($validated['domain'] ?? 'All domains') !== 'All domains') {
            $abilities[] = 'domain:'.$validated['domain'];
        }

        // A custom date wins over the preset; it expires at the end of that day.
        $days = (int) ($validated['expires_in_days'] ?? 0);
        $expiresAt = match (true) {
            ! empty($validated['expires_at']) => Carbon::parse($validated['expires_at'])->endOfDay(),
            $days > 0 => now()->addDays($days),
            default => null,
        };

        $issued = ApiKey::issue(
            $organization,
            $validated['name'],
            $request->user(),
            $abilities,
            $expiresAt,
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

    /**
     * Issue a new secret with the same name, permissions, domain scope and
     * validity period; the old key is revoked. The new key is shown once.
     */
    public function rotate(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($apiKey->organization_id === $organization->id, 404);

        if ($apiKey->isRevoked()) {
            return redirect()
                ->route('api-keys')
                ->withErrors(['api_key' => 'Revoked keys cannot be rotated.']);
        }

        $issued = $apiKey->rotate($request->user());

        return redirect()
            ->route('api-keys')
            ->with('plain_api_key', $issued['plain'])
            ->with('success', "Rotated “{$apiKey->name}”. The old key no longer works.");
    }

    /**
     * Revoke a key: it stops working immediately but stays in the list
     * (marked Revoked with the date) for the audit trail.
     */
    public function revoke(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($apiKey->organization_id === $organization->id, 404);

        $apiKey->revoke();

        return redirect()
            ->route('api-keys')
            ->with('success', "Revoked “{$apiKey->name}”.");
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

    /**
     * Export API key metadata as CSV. Never exports secrets or hashes.
     */
    public function export(Request $request): StreamedResponse
    {
        $organization = CurrentOrganization::from($request);

        $keys = $organization->apiKeys()
            ->latest()
            ->get();

        $tz = $organization->getTimezone();
        $filename = sprintf('api-keys-%s-%s.csv', $organization->slug ?? 'workspace', now()->format('Y-m-d'));

        return response()->streamDownload(function () use ($keys, $tz) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Name',
                'Prefix',
                'Permission',
                'Domain Scope',
                'Created',
                'Last Used',
                'Expires',
                'Status',
            ]);

            foreach ($keys as $key) {
                $abilities = $key->abilities ?? ['*'];
                $permission = in_array('emails:send', $abilities, true) && ! in_array('*', $abilities, true)
                    ? 'Sending access'
                    : 'Full access';

                $domain = 'All domains';
                foreach ($abilities as $ability) {
                    if (is_string($ability) && str_starts_with($ability, 'domain:')) {
                        $domain = substr($ability, 7);
                        break;
                    }
                }

                $status = $key->isRevoked()
                    ? 'Revoked'
                    : ($key->isExpired() ? 'Expired' : 'Active');

                fputcsv($out, [
                    $this->escapeCsvCell($key->name),
                    $key->key_prefix,
                    $permission,
                    $this->escapeCsvCell($domain),
                    $key->created_at?->timezone($tz)->toDateTimeString() ?? '',
                    $key->last_used_at?->timezone($tz)->toDateTimeString() ?? 'Never',
                    $key->expires_at?->timezone($tz)->toDateTimeString() ?? 'Never',
                    $status,
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Escape a cell value to prevent CSV formula injection.
     * Prefix cells starting with =, +, -, or @ with a single quote.
     */
    protected function escapeCsvCell(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $first = $value[0];

        if (in_array($first, ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }
}
