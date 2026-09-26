<?php

namespace App\Http\Controllers\Admin;

use App\Ai\AiManager;
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
    public function index(PlatformSettings $settings, AiManager $ai): Response
    {
        return Inertia::render('Admin/Settings/Index', [
            'settings' => [
                'signup_open' => $settings->signupOpen(),
                'platform_name' => $settings->get('platform_name', ''),
                'support_email' => $settings->get('support_email', ''),
                'default_workspace_provider_id' => $settings->defaultWorkspaceProviderId(),
                'trial_days' => $settings->trialDays(),
                'ai' => $settings->aiSettings(),
            ],
            'aiProviders' => $ai->availableProviders(),
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
        $featureKeys = array_keys(PlatformSettings::AI_FEATURES);

        $validated = $request->validate([
            'signup_open' => ['required', 'boolean'],
            'platform_name' => ['nullable', 'string', 'max:80'],
            'support_email' => ['nullable', 'email', 'max:190'],
            'default_workspace_provider_id' => [
                'nullable', 'integer',
                Rule::exists('mail_providers', 'id')->where(fn ($q) => $q->where('status', '!=', 'disabled')),
            ],
            'trial_days' => ['required', 'integer', 'min:1', 'max:365'],
            'ai_enabled' => ['required', 'boolean'],
            'ai_features' => ['required', 'array'],
            'ai_features.*' => ['required', 'boolean'],
            'ai_provider' => ['required', 'string', Rule::in(PlatformSettings::AI_PROVIDERS)],
            'ai_model' => ['nullable', 'string', 'max:120'],
            'ai_api_key' => ['nullable', 'string', 'max:500'],
            'ai_clear_api_key' => ['sometimes', 'boolean'],
        ]);

        $unknown = array_diff(array_keys($validated['ai_features']), $featureKeys);
        if ($unknown !== []) {
            return back()->withErrors([
                'ai_features' => 'Unknown AI feature: '.implode(', ', $unknown),
            ]);
        }

        $missing = array_diff($featureKeys, array_keys($validated['ai_features']));
        if ($missing !== []) {
            return back()->withErrors([
                'ai_features' => 'Missing AI feature toggle: '.implode(', ', $missing),
            ]);
        }

        $aiValues = [
            'ai_enabled' => (bool) $validated['ai_enabled'],
            'ai_provider' => $validated['ai_provider'],
            'ai_model' => trim((string) ($validated['ai_model'] ?? '')) ?: null,
        ];

        foreach ($featureKeys as $key) {
            $aiValues['ai_'.$key] = (bool) $validated['ai_features'][$key];
        }

        if (! empty($validated['ai_clear_api_key'])) {
            $settings->clearAiApiKey();
        } elseif (! empty($validated['ai_api_key'])) {
            $aiValues['ai_api_key'] = $validated['ai_api_key'];
        }

        $settings->set([
            'signup_open' => (bool) $validated['signup_open'],
            'platform_name' => trim((string) ($validated['platform_name'] ?? '')) ?: null,
            'support_email' => trim((string) ($validated['support_email'] ?? '')) ?: null,
            'default_workspace_provider_id' => $validated['default_workspace_provider_id'] ?? null,
            'trial_days' => (int) $validated['trial_days'],
            ...$aiValues,
        ]);

        return back()->with('success', 'Platform settings saved.');
    }
}
