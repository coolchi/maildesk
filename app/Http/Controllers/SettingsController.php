<?php

namespace App\Http\Controllers;

use App\Services\Billing\BillingService;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function show(Request $request, string $tab = 'usage'): Response
    {
        $organization = CurrentOrganization::from($request);
        $organization->loadMissing(['subscription.plan', 'mailProvider']);

        $settings = $organization->settings ?? [];
        $plan = $organization->subscription?->plan;
        $emailsLimit = $plan?->emails ?? 50000;
        $contactsLimit = $plan?->contacts ?? 1000;
        $seatsLimit = $plan?->seats ?? ($organization->seats ?: 10);

        $emailsUsed = $organization->messages()
            ->where('direction', 'outbound')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $usage = [
            'transactional' => [
                'plan' => $organization->plan ?: 'Free',
                'monthly' => [
                    'used' => $emailsUsed,
                    'limit' => $emailsLimit ?: 50000,
                    'renews' => $organization->subscription?->renews_at
                        ?? now()->addMonth()->startOfMonth()->format('M j, Y'),
                ],
                'daily' => 'Unlimited',
            ],
            'marketing' => [
                'plan' => $organization->product === 'marketing' ? ($organization->plan ?: 'Free') : 'Free',
                'contacts' => [
                    'used' => method_exists($organization, 'contacts')
                        ? $organization->contacts()->count()
                        : 0,
                    'limit' => $contactsLimit ?: 1000,
                ],
                'segments' => [
                    'used' => method_exists($organization, 'segments')
                        ? $organization->segments()->count()
                        : 0,
                    'limit' => 3,
                ],
                'broadcasts' => 'Unlimited',
            ],
            'team' => [
                'plan' => $organization->plan ?: 'Free',
                'seats' => [
                    'used' => $organization->users()->count(),
                    'limit' => $seatsLimit ?: 10,
                ],
            ],
        ];

        $billing = [
            'email' => $organization->owner_email,
            'fullName' => $organization->owner_name,
            'subscriptions' => $organization->subscriptions()
                ->with('plan')
                ->latest('id')
                ->get()
                ->map(fn ($sub) => [
                    'id' => $sub->key ?? (string) $sub->id,
                    'name' => ucfirst((string) $sub->product).' · '.$sub->plan_name,
                    'renews' => $sub->renews_at ? 'Renews '.$sub->renews_at : null,
                    'quota' => $sub->plan?->emails
                        ? number_format($sub->plan->emails).' emails'
                        : ($sub->plan?->contacts
                            ? number_format($sub->plan->contacts).' contacts'
                            : '—'),
                    'price' => '$'.number_format((int) $sub->price, 2).' / mo',
                    'status' => $sub->status,
                ])
                ->values()
                ->all(),
        ];

        return Inertia::render('Settings/Index', [
            'tab' => $tab,
            'usage' => $usage,
            'billing' => $billing,
            // Monipay plan upgrades + payment history (no secret material).
            'payments' => fn () => app(BillingService::class)->settingsProps($request->user(), $organization),
            'settings' => [
                'unsubscribe' => $settings['unsubscribe'] ?? [
                    'brand' => $organization->name,
                    'headline' => "You've been unsubscribed",
                    'message' => "Thanks for letting us know. You won't receive marketing emails from us anymore.",
                    'askReason' => true,
                    'showPreferences' => true,
                    'preferencesUrl' => '',
                    'buttonLabel' => 'Manage preferences',
                    'accent' => '#0891b2',
                    'footer' => 'If this was a mistake, you can update your preferences anytime.',
                ],
                'documents' => $settings['documents'] ?? [],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);

        $validated = $request->validate([
            'unsubscribe' => ['sometimes', 'array'],
            'unsubscribe.brand' => ['nullable', 'string', 'max:120'],
            'unsubscribe.headline' => ['nullable', 'string', 'max:255'],
            'unsubscribe.message' => ['nullable', 'string', 'max:2000'],
            'unsubscribe.askReason' => ['sometimes', 'boolean'],
            'unsubscribe.showPreferences' => ['sometimes', 'boolean'],
            'unsubscribe.preferencesUrl' => ['nullable', 'string', 'max:500'],
            'unsubscribe.buttonLabel' => ['nullable', 'string', 'max:120'],
            'unsubscribe.accent' => ['nullable', 'string', 'max:32'],
            'unsubscribe.footer' => ['nullable', 'string', 'max:500'],
            'documents' => ['sometimes', 'array'],
        ]);

        $settings = $organization->settings ?? [];

        if (array_key_exists('unsubscribe', $validated)) {
            $settings['unsubscribe'] = array_merge(
                $settings['unsubscribe'] ?? [],
                $validated['unsubscribe'],
            );
        }

        if (array_key_exists('documents', $validated)) {
            $settings['documents'] = $validated['documents'];
        }

        $organization->update(['settings' => $settings]);

        return back()->with('success', 'Settings saved.');
    }
}
