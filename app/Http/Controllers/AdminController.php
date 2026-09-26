<?php

namespace App\Http\Controllers;

use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\OrganizationHost;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Domains\HostDnsVerifier;
use App\Services\EmailUsage;
use App\Services\Providers\ProviderConnectionTester;
use App\Services\RevenueService;
use App\Support\PlanNairaPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function dashboard(): Response
    {
        $accounts = Organization::query()
            ->with('mailProvider')
            ->latest('id')
            ->get()
            ->pipe(fn ($orgs) => app(EmailUsage::class)->attach($orgs));

        $providers = MailProvider::query()
            ->withCount('organizations')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $subscriptions = Subscription::query()
            ->with(['organization', 'plan'])
            ->whereHas('organization')
            ->latest('id')
            ->get();

        $hosts = OrganizationHost::query()
            ->with('organization')
            ->whereHas('organization')
            ->latest('id')
            ->get();

        // Shared MRR computation (active paid subs, yearly ÷ 12).
        $activeMrr = app(RevenueService::class)->mrr();

        $endedRecently = Subscription::query()
            ->whereIn('status', ['canceled', 'cancelled', 'past_due'])
            ->where('updated_at', '>=', now()->subDays(30))
            ->count();
        $activeCount = $subscriptions->where('status', 'active')->count();
        $churnBase = $activeCount + $endedRecently;
        $churn30d = $churnBase > 0
            ? round(($endedRecently / $churnBase) * 100, 1).'%'
            : '—';

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'accounts' => $accounts->count(),
                'activeSubscriptions' => $subscriptions->where('status', 'active')->count(),
                'mrr' => $activeMrr,
                'customSubdomains' => $hosts->where('is_custom', true)->count(),
                'trialAccounts' => $accounts->where('status', 'trial')->count(),
                'churn30d' => $churn30d,
                'providers' => $providers->where('status', 'active')->count(),
            ],
            'accounts' => $accounts->take(5)->map->toAdminArray()->values(),
            'subscriptions' => $subscriptions->take(5)->map->toAdminArray()->values(),
            'pendingHosts' => $hosts->where('status', '!=', 'active')->values()->map->toAdminArray(),
            'orphanedCount' => $accounts->filter(
                fn (Organization $org) => $org->mail_provider_id === null
                    || $org->mailProvider === null,
            )->count(),
            'providers' => $providers->map->toAdminArray()->values(),
        ]);
    }

    public function accounts(): Response
    {
        $accounts = Organization::query()
            ->with('mailProvider')
            ->orderBy('name')
            ->get()
            ->pipe(fn ($orgs) => app(EmailUsage::class)->attach($orgs))
            ->map->toAdminArray()
            ->values();

        $providers = MailProvider::query()
            ->withCount('organizations')
            ->orderBy('name')
            ->get()
            ->map->toAdminArray()
            ->values();

        return Inertia::render('Admin/Accounts/Index', [
            'accounts' => $accounts,
            'providers' => $providers,
        ]);
    }

    public function showAccount(Organization $organization): Response
    {
        $organization->load(['mailProvider', 'hosts', 'subscription.plan']);

        $providers = MailProvider::query()
            ->withCount('organizations')
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->map->toAdminArray()
            ->values();

        return Inertia::render('Admin/Accounts/Show', [
            'id' => $organization->id,
            'account' => $organization->toAdminArray(),
            'subscription' => $organization->subscription?->toAdminArray(),
            'hosts' => $organization->hosts->map->toAdminArray()->values(),
            'providers' => $providers,
        ]);
    }

    public function updateAccountProvider(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'provider' => [
                'required',
                'string',
                Rule::exists('mail_providers', 'key')->where(fn ($q) => $q->where('status', 'active')),
            ],
        ]);

        $provider = MailProvider::query()->where('key', $validated['provider'])->firstOrFail();

        $organization->update([
            'mail_provider_id' => $provider->id,
            // default_provider is a DRIVER name for MailManager's fallback, not the key.
            'default_provider' => $provider->driver,
        ]);

        return back()->with('success', "Mail provider set to {$provider->name}.");
    }

    public function updateAccountStatus(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'trial', 'past_due', 'suspended'])],
        ]);

        $organization->update(['status' => $validated['status']]);

        $label = $validated['status'] === 'suspended' ? 'suspended' : 'set to '.$validated['status'];

        return back()->with('success', "Account {$label}.");
    }

    public function updateAccountHosts(Request $request, Organization $organization): RedirectResponse
    {
        $base = (string) config('maildesk.base_domain', 'maildesk.test');

        $validated = $request->validate([
            'subdomain' => [
                'nullable',
                'string',
                'max:63',
                'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/',
                Rule::unique('organizations', 'subdomain')->ignore($organization->id),
            ],
            'custom_domain' => ['nullable', 'string', 'max:255'],
        ]);

        $subdomain = isset($validated['subdomain'])
            ? strtolower(trim((string) $validated['subdomain']))
            : null;
        $customDomain = isset($validated['custom_domain'])
            ? strtolower(trim((string) $validated['custom_domain']))
            : null;

        if ($customDomain === '') {
            $customDomain = null;
        }

        $organization->update([
            'subdomain' => $subdomain ?: $organization->subdomain,
            'custom_domain' => $customDomain,
        ]);

        if ($subdomain) {
            OrganizationHost::query()->updateOrCreate(
                ['host' => $subdomain.'.'.$base],
                [
                    'organization_id' => $organization->id,
                    'subdomain' => $subdomain,
                    'status' => 'active',
                    'ssl' => true,
                    'is_custom' => false,
                ],
            );
        }

        if ($customDomain) {
            OrganizationHost::query()->updateOrCreate(
                ['host' => $customDomain],
                [
                    'organization_id' => $organization->id,
                    'subdomain' => null,
                    'status' => 'pending_dns',
                    'ssl' => false,
                    'is_custom' => true,
                ],
            );
        }

        return back()->with('success', 'Hosts updated.');
    }

    public function plans(): Response
    {
        return Inertia::render('Admin/Plans/Index', [
            'plans' => Plan::query()->withCount('subscriptions')->orderBy('product')->orderBy('price')->get()->map->toAdminArray()->values(),
            'monipayMinKobo' => PlanNairaPrice::minKobo(),
        ]);
    }

    public function updatePlan(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'price' => ['required', 'integer', 'min:0'],
            'price_ngn' => PlanNairaPrice::rules(),
            'interval' => ['sometimes', Rule::in(['month', 'year'])],
            'emails' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'contacts' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'seats' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'featured' => ['sometimes', 'boolean'],
            'features' => ['nullable', 'array'],
            'features.*.id' => ['nullable', 'string', 'max:64'],
            'features.*.label' => ['required_with:features', 'string', 'max:255'],
            'features.*.included' => ['boolean'],
        ]);

        $features = collect($validated['features'] ?? [])
            ->map(fn (array $feature, int $index) => [
                'id' => $feature['id'] ?? 'f'.($index + 1),
                'label' => $feature['label'],
                'included' => (bool) ($feature['included'] ?? false),
            ])
            ->values()
            ->all();

        PlanNairaPrice::assertMinimum((int) $validated['price'], $validated['price_ngn'] ?? null);

        $plan->update([
            'name' => $validated['name'],
            'price' => $validated['price'],
            'featured' => (bool) ($validated['featured'] ?? false),
            'features' => $features,
            // Optional fields only change when sent.
            ...(array_key_exists('price_ngn', $validated) ? ['price_kobo' => PlanNairaPrice::toKobo($validated['price_ngn'])] : []),
            ...collect(['interval', 'emails', 'contacts', 'seats'])
                ->filter(fn (string $field) => array_key_exists($field, $validated))
                ->mapWithKeys(fn (string $field) => [$field => $validated[$field]])
                ->all(),
        ]);

        Subscription::query()
            ->where('plan_id', $plan->id)
            ->update([
                'plan_name' => $plan->name,
                'price' => $plan->price,
            ]);

        Organization::query()
            ->whereHas('subscription', fn ($query) => $query->where('plan_id', $plan->id))
            ->update(['plan' => $plan->name]);

        // Price / interval changes: refresh organizations.mrr for affected accounts.
        app(RevenueService::class)->syncPlan($plan);

        return back()->with('success', "{$plan->name} plan saved.");
    }

    public function storePlan(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:64', 'alpha_dash', 'unique:plans,key'],
            'product' => ['required', Rule::in(['transactional', 'marketing'])],
            'name' => ['required', 'string', 'max:120'],
            'price' => ['required', 'integer', 'min:0'],
            'price_ngn' => PlanNairaPrice::rules(),
            'interval' => ['sometimes', Rule::in(['month', 'year'])],
            'emails' => ['nullable', 'integer', 'min:0'],
            'contacts' => ['nullable', 'integer', 'min:0'],
            'seats' => ['nullable', 'integer', 'min:0'],
            'featured' => ['sometimes', 'boolean'],
        ]);

        PlanNairaPrice::assertMinimum((int) $validated['price'], $validated['price_ngn'] ?? null);

        Plan::query()->create([
            'key' => $validated['key'],
            'product' => $validated['product'],
            'name' => $validated['name'],
            'price' => $validated['price'],
            'price_kobo' => PlanNairaPrice::toKobo($validated['price_ngn'] ?? null),
            'interval' => $validated['interval'] ?? 'month',
            'emails' => $validated['emails'] ?? null,
            'contacts' => $validated['contacts'] ?? null,
            'seats' => $validated['seats'] ?? null,
            'featured' => (bool) ($validated['featured'] ?? false),
            'features' => [],
        ]);

        return back()->with('success', "Plan {$validated['name']} created.");
    }

    public function subscriptions(): Response
    {
        return Inertia::render('Admin/Subscriptions/Index', [
            'subscriptions' => Subscription::query()
                ->with(['organization', 'plan'])
                ->whereHas('organization')
                ->latest('id')
                ->get()
                ->map->toAdminArray()
                ->values(),
            'plans' => Plan::query()->orderBy('product')->orderBy('price')->get()->map->toAdminArray()->values(),
        ]);
    }

    public function updateSubscription(Request $request, Subscription $subscription): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => ['sometimes', 'integer', 'exists:plans,id'],
            'status' => ['sometimes', Rule::in(['active', 'past_due', 'trial', 'canceled', 'cancelled'])],
        ]);

        if (isset($validated['plan_id'])) {
            $plan = Plan::query()->findOrFail($validated['plan_id']);
            $subscription->plan_id = $plan->id;
            $subscription->plan_name = $plan->name;
            $subscription->price = $plan->price;
            $subscription->product = $plan->product;
        }

        if (isset($validated['status'])) {
            $subscription->status = $validated['status'] === 'cancelled'
                ? 'canceled'
                : $validated['status'];
        }

        $subscription->save();

        if ($subscription->organization) {
            $subscription->organization->update([
                'plan' => $subscription->plan_name,
                'product' => $subscription->product,
                'mrr' => app(RevenueService::class)->organizationMrr($subscription->organization),
            ]);
        }

        return back()->with('success', 'Subscription updated.');
    }

    public function subdomains(): Response
    {
        return Inertia::render('Admin/Subdomains/Index', [
            'hosts' => OrganizationHost::query()
                ->with('organization')
                ->whereHas('organization')
                ->latest('id')
                ->get()
                ->map->toAdminArray()
                ->values(),
            'accounts' => Organization::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Organization $org) => [
                    'id' => $org->id,
                    'name' => $org->name,
                ])
                ->values(),
        ]);
    }

    public function storeHost(Request $request): RedirectResponse
    {
        $base = (string) config('maildesk.base_domain', 'maildesk.test');

        $validated = $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'type' => ['required', Rule::in(['platform', 'custom'])],
            'subdomain' => [
                'required_if:type,platform',
                'nullable',
                'string',
                'max:63',
                'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/',
            ],
            'host' => [
                'required_if:type,custom',
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        $isCustom = $validated['type'] === 'custom';
        $subdomain = $isCustom ? null : strtolower(trim((string) $validated['subdomain']));
        $host = $isCustom
            ? strtolower(trim((string) $validated['host']))
            : $subdomain.'.'.$base;

        if (OrganizationHost::query()->whereRaw('LOWER(host) = ?', [$host])->exists()) {
            return back()->withErrors(['host' => 'That host is already registered.']);
        }

        OrganizationHost::query()->create([
            'organization_id' => $validated['organization_id'],
            'subdomain' => $subdomain,
            'host' => $host,
            'status' => $isCustom ? 'pending_dns' : 'provisioning',
            'ssl' => false,
            'is_custom' => $isCustom,
        ]);

        if (! $isCustom) {
            Organization::query()->whereKey($validated['organization_id'])->update([
                'subdomain' => $subdomain,
            ]);
        } else {
            Organization::query()->whereKey($validated['organization_id'])->update([
                'custom_domain' => $host,
            ]);
        }

        return back()->with('success', "Host {$host} queued.");
    }

    public function verifyHost(OrganizationHost $organizationHost): RedirectResponse
    {
        // Real, read-only DNS checks; active only when every required check passes.
        $result = app(HostDnsVerifier::class)->verify($organizationHost);

        if (! $result['passed']) {
            return back()
                ->withErrors(['host' => 'DNS check failed: '.implode('; ', $result['failed'])])
                ->with('error', "{$organizationHost->host} not verified: ".implode('; ', $result['failed']));
        }

        return back()->with('success', "{$organizationHost->host} verified.");
    }

    public function providers(): Response
    {
        return Inertia::render('Admin/Providers/Index', [
            'providers' => MailProvider::query()
                ->withCount('organizations')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
                ->map->toAdminArray()
                ->values(),
        ]);
    }

    public function storeProvider(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'driver' => ['required', 'string', 'max:40'],
            'type' => ['required', Rule::in(['api', 'smtp'])],
            'api_base' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'regions' => ['nullable', 'array'],
            'features' => ['nullable', 'array'],
            'config' => ['nullable', 'array'],
            'config.*.key' => ['required_with:config', 'string', 'max:80'],
            'config.*.value' => ['nullable', 'string'],
            'config.*.secret' => ['boolean'],
        ]);

        $key = $validated['driver'].'_'.uniqid();

        MailProvider::query()->create([
            'key' => $key,
            'name' => $validated['name'],
            'driver' => $validated['driver'],
            'type' => $validated['type'],
            'status' => 'active',
            'is_default' => MailProvider::query()->count() === 0,
            'api_base' => $validated['api_base'] ?? null,
            'regions' => $validated['regions'] ?? [],
            'features' => $validated['features'] ?? [],
            'description' => $validated['description'] ?? null,
            'config' => $validated['config'] ?? [],
        ]);

        return back()->with('success', "Added “{$validated['name']}”.");
    }

    public function updateProvider(Request $request, MailProvider $mailProvider): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'status' => ['required', Rule::in(['active', 'disabled'])],
            'api_base' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
            'config' => ['nullable', 'array'],
            'config.*.key' => ['required_with:config', 'string', 'max:80'],
            'config.*.value' => ['nullable', 'string'],
            'config.*.secret' => ['boolean'],
        ]);

        $makeDefault = ($validated['is_default'] ?? false) === true;

        // Validate the default/disabled combination BEFORE touching any row.
        if ($validated['status'] === 'disabled' && ($makeDefault || $mailProvider->is_default)) {
            return back()->withErrors(['status' => 'Switch default provider before disabling.']);
        }

        DB::transaction(function () use ($validated, $mailProvider, $makeDefault): void {
            if ($makeDefault) {
                MailProvider::query()->where('id', '!=', $mailProvider->id)->update(['is_default' => false]);
                $mailProvider->is_default = true;
            }

            $mailProvider->fill([
                'name' => $validated['name'],
                'status' => $validated['status'],
                'api_base' => $validated['api_base'] ?? null,
                'description' => $validated['description'] ?? $mailProvider->description,
                'config' => array_key_exists('config', $validated) && $validated['config'] !== null
                    ? $mailProvider->mergeConfigKeepingSecrets($validated['config'])
                    : $mailProvider->config,
            ])->save();
        });

        return back()->with('success', "{$mailProvider->name} config saved.");
    }

    /**
     * Live "test connection" for a provider; stores nothing. JSON only:
     * {ok, message, latency_ms, driver} with secrets scrubbed.
     */
    public function testProvider(MailProvider $mailProvider): JsonResponse
    {
        return response()->json(app(ProviderConnectionTester::class)->test($mailProvider));
    }

    public function destroyProvider(MailProvider $mailProvider): RedirectResponse
    {
        if ($mailProvider->is_default) {
            return back()->withErrors(['provider' => 'Cannot delete the default provider.']);
        }

        $name = $mailProvider->name;
        $fallback = MailProvider::query()
            ->where('is_default', true)
            ->where('status', 'active')
            ->whereKeyNot($mailProvider->id)
            ->first();

        $moved = DB::transaction(function () use ($mailProvider, $fallback): int {
            // Reassign tenants to the platform default instead of orphaning them.
            $moved = $fallback
                ? Organization::withTrashed()
                    ->where('mail_provider_id', $mailProvider->id)
                    ->update(['mail_provider_id' => $fallback->id, 'default_provider' => $fallback->driver])
                : 0;

            $mailProvider->delete();

            return $moved;
        });

        return back()->with('success', $moved > 0
            ? "Deleted “{$name}”; {$moved} account(s) moved to {$fallback->name}."
            : "Deleted “{$name}”.");
    }
}
