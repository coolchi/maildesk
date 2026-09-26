<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Billing\BillingException;
use App\Services\Billing\BillingService;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session-authenticated plan upgrade flow (web routes, CSRF protected).
 * Fulfilment never trusts the browser: the callback only triggers a
 * server-side verify with the private key.
 */
class MonipayController extends Controller
{
    public const SESSION_KEY = 'monipay_pending_reference';

    public function __construct(public BillingService $billing) {}

    public function initialize(Request $request): Response|JsonResponse|RedirectResponse
    {
        $organization = CurrentOrganization::from($request);

        abort_unless(
            $this->billing->canManageBilling($request->user(), $organization),
            403,
            'Only workspace owners and admins can manage billing.',
        );

        $validated = $request->validate([
            'plan' => ['required', 'string', 'exists:plans,key'],
        ]);

        $plan = Plan::query()->where('key', $validated['plan'])->firstOrFail();

        if (! $this->billing->isConfigured()) {
            throw ValidationException::withMessages(['plan' => 'Payments are not configured.']);
        }

        // Send the customer back to the host they started on (the tenant's own subdomain).
        $callbackUrl = $request->getSchemeAndHttpHost().route('billing.monipay.callback', [], false);
        $webhookUrl = $request->getSchemeAndHttpHost().route('payments.monipay.webhook', [], false);

        try {
            $result = $this->billing->initialize($organization, $request->user(), $plan, $callbackUrl, $webhookUrl);
        } catch (BillingException $e) {
            throw ValidationException::withMessages(['plan' => $e->getMessage()]);
        }

        // Callback query parameter names are undocumented; remember the
        // reference so the callback can fall back to it.
        $request->session()->put(self::SESSION_KEY, $result['payment']->reference);

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'authorization_url' => $result['authorization_url'],
                'access_code' => $result['access_code'],
                'reference' => $result['payment']->reference,
            ]);
        }

        return Inertia::location($result['authorization_url']);
    }

    public function callback(Request $request): RedirectResponse
    {
        $reference = collect(['reference', 'trxref', 'ref', 'trans_id', 'order_id'])
            ->map(fn (string $key) => $request->query($key))
            ->first(fn ($value) => is_string($value) && trim($value) !== '')
            ?? $request->session()->get(self::SESSION_KEY);

        $payment = $this->billing->findByReference($reference);
        $user = $request->user();

        $canAccess = $payment && $user && (
            $user->isPlatformAdmin()
            || $user->organizations()->whereKey($payment->organization_id)->exists()
        );

        if (! $canAccess) {
            return redirect()->route('settings', 'billing')
                ->with('error', 'We could not find that payment.');
        }

        $outcome = $this->billing->verifyAndFulfil($payment, 'callback');

        if ($outcome['result'] !== 'error' && $outcome['result'] !== 'pending') {
            $request->session()->forget(self::SESSION_KEY);
        }

        [$type, $message] = match ($outcome['result']) {
            'paid', 'already_paid' => ['success', 'Payment received. Your plan is now active.'],
            'pending' => ['error', 'Your payment is still being processed. We will activate your plan as soon as it is confirmed.'],
            'abandoned' => ['error', 'The payment was not completed.'],
            'failed' => ['error', 'The payment failed. You have not been charged for an upgrade.'],
            'mismatch' => ['error', 'We could not confirm this payment automatically. Our team will review it.'],
            default => ['error', 'We could not confirm your payment yet. Please refresh in a moment.'],
        };

        return redirect()->route('settings', 'billing')->with($type, $message);
    }
}
