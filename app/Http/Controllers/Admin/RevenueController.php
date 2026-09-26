<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\RevenueService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class RevenueController extends Controller
{
    public function index(RevenueService $revenue): Response
    {
        $subscriptions = $revenue->activePaidSubscriptions();
        $mrr = $revenue->mrr();

        $payments = [];
        $monthly = [];
        $paymentsAvailable = Schema::hasTable('payments');

        if ($paymentsAvailable) {
            // Read-only view of fulfilled Monipay payments (receipts).
            $rows = Payment::query()
                ->with(['organization' => fn ($q) => $q->withTrashed(), 'plan'])
                ->where('status', 'paid')
                ->whereNotNull('fulfilled_at')
                ->latest('paid_at')
                ->limit(500)
                ->get();

            $payments = $rows->take(100)->map(fn (Payment $p) => [
                'id' => $p->id,
                'reference' => $p->reference,
                'account' => $p->organization?->name,
                'accountId' => $p->organization?->trashed() ? null : $p->organization_id,
                'plan' => $p->plan?->name ?? $p->plan_key,
                'amount' => $p->amount,
                'amountFormatted' => '₦'.number_format($p->amount / 100, 2),
                'currency' => $p->currency,
                'channel' => $p->channel,
                'paidAt' => ($p->paid_at ?? $p->fulfilled_at)?->toIso8601String(),
            ])->values()->all();

            $monthly = $rows
                ->groupBy(fn (Payment $p) => ($p->paid_at ?? $p->fulfilled_at)->format('Y-m'))
                ->map(fn ($group, string $month) => [
                    'month' => $month,
                    'label' => Carbon::createFromFormat('Y-m', $month)->format('M Y'),
                    'count' => $group->count(),
                    'total' => (int) $group->sum('amount'),
                    'totalFormatted' => '₦'.number_format($group->sum('amount') / 100, 2),
                ])
                ->sortKeysDesc()
                ->values()
                ->all();
        }

        return Inertia::render('Admin/Revenue/Index', [
            'summary' => [
                'mrr' => $mrr,
                'arr' => round($mrr * 12, 2),
                'paidSubscriptions' => $subscriptions->count(),
                'payingAccounts' => $subscriptions->pluck('organization_id')->unique()->count(),
                'currency' => 'USD',
            ],
            'breakdown' => $revenue->breakdown(),
            'paymentsAvailable' => $paymentsAvailable,
            'payments' => $payments,
            'monthly' => $monthly,
        ]);
    }
}
