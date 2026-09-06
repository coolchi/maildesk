<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationHost;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class WorkspaceController extends Controller
{
    public function store(Request $request, TenantResolver $tenants): RedirectResponse|SymfonyResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $name = trim($validated['name']);
        $baseSlug = Str::slug($name) ?: 'workspace';
        $slug = $baseSlug;
        $suffix = 1;

        while (Organization::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        $subdomain = $slug;
        $suffix = 1;
        while (
            Organization::query()->where('subdomain', $subdomain)->exists()
            || OrganizationHost::query()->where('subdomain', $subdomain)->exists()
        ) {
            $subdomain = $slug.'-'.$suffix;
            $suffix++;
        }

        $base = $tenants->baseDomain();

        $organization = Organization::query()->create([
            'name' => $name,
            'slug' => $slug,
            'status' => 'trial',
            'plan' => 'Free',
            'product' => 'transactional',
            'subdomain' => $subdomain,
            'seats' => 1,
            'emails_30d' => 0,
            'mrr' => 0,
            'region' => 'us-east-1',
            'owner_name' => $user->name,
            'owner_email' => $user->email,
            'provisioned_at' => now(),
            'settings' => [],
        ]);

        $organization->users()->attach($user->id, ['role' => 'owner']);

        OrganizationHost::query()->create([
            'organization_id' => $organization->id,
            'subdomain' => $subdomain,
            'host' => $subdomain.'.'.$base,
            'status' => 'active',
            'ssl' => true,
            'is_custom' => false,
        ]);

        $request->session()->put('current_organization_id', $organization->id);
        $request->session()->save();

        $path = '/emails';

        if ($organization->subdomain && $tenants->sharesSessionAcrossSubdomains()) {
            $url = $tenants->workspaceUrl($organization, $path);

            if ($request->header('X-Inertia')) {
                return Inertia::location($url);
            }

            return redirect()->away($url);
        }

        return redirect()->to($path)->with('success', "Created {$organization->name}.");
    }

    public function switch(Request $request, Organization $organization, TenantResolver $tenants): RedirectResponse|SymfonyResponse
    {
        $user = $request->user();

        abort_unless(
            $user->isPlatformAdmin() || $user->organizations()->whereKey($organization->id)->exists(),
            403,
        );

        $request->session()->put('current_organization_id', $organization->id);
        $request->session()->save();

        $path = $request->string('redirect')->toString() ?: '/emails';
        if (! str_starts_with($path, '/')) {
            $path = '/emails';
        }

        if ($organization->subdomain && $tenants->sharesSessionAcrossSubdomains()) {
            $url = $tenants->workspaceUrl($organization, $path);

            // Inertia XHR cannot follow cross-host 302s; force a window navigation.
            if ($request->header('X-Inertia')) {
                return Inertia::location($url);
            }

            return redirect()->away($url);
        }

        return redirect()->to($path);
    }
}
