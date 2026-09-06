<?php

namespace App\Http\Controllers;

use App\Support\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MetricsController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = CurrentOrganization::from($request);
        $days = 15;
        $since = now()->subDays($days - 1)->startOfDay();

        $outbound = $organization->messages()
            ->where('direction', 'outbound')
            ->where('created_at', '>=', $since);

        $sent = (clone $outbound)->count();
        $delivered = (clone $outbound)->whereIn('status', ['delivered', 'sent'])->count();
        $bounced = (clone $outbound)->where('status', 'bounced')->count();
        $deliverability = $sent > 0
            ? round(($delivered / $sent) * 100, 1)
            : 0.0;

        $stats = [
            'sent' => $sent,
            'delivered' => $delivered,
            'bounced' => $bounced,
            'deliverability' => $deliverability,
        ];

        $byDay = $organization->messages()
            ->where('direction', 'outbound')
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw("SUM(CASE WHEN status IN ('delivered','sent') THEN 1 ELSE 0 END) as delivered")
            ->selectRaw("SUM(CASE WHEN status = 'bounced' THEN 1 ELSE 0 END) as bounced")
            ->selectRaw("SUM(CASE WHEN status IN ('queued','scheduled','delayed') THEN 1 ELSE 0 END) as delayed")
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $series = [];
        $bounceSeries = [];
        $complaintSeries = [];

        for ($i = 0; $i < $days; $i++) {
            $day = $since->copy()->addDays($i);
            $key = $day->toDateString();
            $row = $byDay->get($key);

            $deliveredCount = (int) ($row->delivered ?? 0);
            $bouncedCount = (int) ($row->bounced ?? 0);
            $delayedCount = (int) ($row->delayed ?? 0);

            $series[] = [
                'day' => $day->format('M d'),
                'delivered' => $deliveredCount,
                'bounced' => $bouncedCount,
                'delayed' => $delayedCount,
            ];
            $bounceSeries[] = $bouncedCount;
            $complaintSeries[] = 0;
        }

        return Inertia::render('Metrics/Index', [
            'stats' => $stats,
            'series' => $series,
            'bounceSeries' => $bounceSeries,
            'complaintSeries' => $complaintSeries,
        ]);
    }
}
