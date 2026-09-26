<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MailProvider;
use App\Services\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PlatformSettingsController extends Controller
{
    public function index(PlatformSettings $settings): Response
    {
        return Inertia::render('Admin/Settings/Index', [
            'settings' => [
                'signup_open' => $settings->signupOpen(),
                'platform_name' => $settings->get('platform_name', ''),
                'support_email' => $settings->get('support_email', ''),
                'default_workspace_provider_id' => $settings->defaultWorkspaceProviderId(),
            ],
            'providers' => MailProvider::query()
                ->where('status', '!=', 'disabled')
                ->orderBy('name')
                ->get(['id', 'name', 'driver', 'is_default'])
                ->map(fn (MailProvider $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'driver' => $p->driver,
                    'isDefault' => (bool) $p->is_default,
                ])->values(),
        ]);
    }

    public function update(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'signup_open' => ['required', 'boolean'],
            'platform_name' => ['nullable', 'string', 'max:80'],
            'support_email' => ['nullable', 'email', 'max:190'],
            'default_workspace_provider_id' => [
                'nullable', 'integer',
                Rule::exists('mail_providers', 'id')->where(fn ($q) => $q->where('status', '!=', 'disabled')),
            ],
        ]);

        $settings->set([
            'signup_open' => (bool) $validated['signup_open'],
            'platform_name' => trim((string) ($validated['platform_name'] ?? '')) ?: null,
            'support_email' => trim((string) ($validated['support_email'] ?? '')) ?: null,
            'default_workspace_provider_id' => $validated['default_workspace_provider_id'] ?? null,
        ]);

        return back()->with('success', 'Platform settings saved.');
    }
}
