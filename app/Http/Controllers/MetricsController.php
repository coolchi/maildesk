<?php

namespace App\Http\Controllers;

use App\Models\Broadcast;
use App\Models\Organization;
use App\Services\BroadcastService;
use App\Support\CurrentOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Outbound delivery metrics from real message statuses and the provider
 * events recorded in message meta (see DeliveryEventService):
 *  - sent: accepted by the provider (sent, delivered, bounced, complained)
 *  - delivered: a delivered event was received (status delivered/complained or meta.delivered_at)
 *  - bounced: hard bounces (status bounced)
 *  - complained: spam complaints (status complained or meta.complained_at)
 *  - opened / clicked: messages with at least one open / click event
 */
class MetricsController extends Controller
{
    public const RANGES = [7, 15, 30, 90];

    protected const ACCEPTED = ['sent', 'delivered', 'bounced', 'complained'];

    public function index(Request $request, BroadcastService $broadcasts): Response
    {
        $organization = CurrentOrganization::from($request);

        $days = (int) $request->integer('days', 15);
        if (! in_array($days, self::RANGES, true)) {
            $days = 15;
        }

        $domainOptions = $organization->domains()->orderBy('name')->pluck('name')->values()->all();
        $domain = strtolower((string) $request->query('domain', ''));
        if (! in_array($domain, $domainOptions, true)) {
            $domain = '';
        }

        $since = now()->subDays($days - 1)->startOfDay();
        $tagOptions = $this->tagOptions($organization, $since);
        $tag = (string) $request->query('tag', '');
        if (! in_array($tag, $tagOptions, true)) {
            $tag = '';
        }

        $base = fn () => $this->baseQuery($organization, $since, $domain, $tag);

        $total = $base()->count();
        $sent = $base()->whereIn('status', self::ACCEPTED)->count();
        $delivered = $this->delivered($base())->count();
        $bounced = $base()->where('status', 'bounced')->count();
        $complained = $this->complained($base())->count();
        $failed = $base()->where('status', 'failed')->count();
        $opened = $base()->whereNotNull('meta->first_opened_at')->count();
        $clicked = $base()->whereNotNull('meta->first_clicked_at')->count();

        // Open/click tracking is "not tracked" until the provider has ever
        // reported such an event for this workspace (tracking disabled).
        $tracking = [
            'opens' => $organization->messages()->where('direction', 'outbound')->whereNotNull('meta->first_opened_at')->exists(),
            'clicks' => $organization->messages()->where('direction', 'outbound')->whereNotNull('meta->first_clicked_at')->exists(),
        ];

        $stats = [
            'emails' => $total,
            'sent' => $sent,
            'delivered' => $delivered,
            'bounced' => $bounced,
            'complained' => $complained,
            'failed' => $failed,
            'opened' => $opened,
            'clicked' => $clicked,
            'deliverability' => $this->rate($delivered, $sent),
            'bounce_rate' => $this->rate($bounced, $sent),
            'complaint_rate' => $this->rate($complained, $delivered),
            'open_rate' => $tracking['opens'] ? $this->rate($opened, $delivered) : null,
            'click_rate' => $tracking['clicks'] ? $this->rate($clicked, $delivered) : null,
        ];

        $perDay = [
            'delivered' => $this->byDay($this->delivered($base())),
            'bounced' => $this->byDay($base()->where('status', 'bounced')),
            'complained' => $this->byDay($this->complained($base())),
            // Accepted by the provider but no delivery event yet.
            'pending' => $this->byDay($base()->whereIn('status', ['sent', 'queued'])->whereNull('meta->delivered_at')),
        ];

        $series = [];
        $bounceSeries = [];
        $complaintSeries = [];

        for ($i = 0; $i < $days; $i++) {
            $day = $since->copy()->addDays($i);
            $key = $day->toDateString();

            $series[] = [
                'day' => $day->format('M d'),
                'delivered' => (int) ($perDay['delivered'][$key] ?? 0),
                'bounced' => (int) ($perDay['bounced'][$key] ?? 0),
                'pending' => (int) ($perDay['pending'][$key] ?? 0),
            ];
            $bounceSeries[] = (int) ($perDay['bounced'][$key] ?? 0);
            $complaintSeries[] = (int) ($perDay['complained'][$key] ?? 0);
        }

        return Inertia::render('Metrics/Index', [
            'stats' => $stats,
            'series' => $series,
            'bounceSeries' => $bounceSeries,
            'complaintSeries' => $complaintSeries,
            'tracking' => $tracking,
            'broadcasts' => $this->broadcastStats($organization, $broadcasts, $since, $domain),
            'filters' => ['days' => $days, 'domain' => $domain ?: null, 'tag' => $tag ?: null],
            'domainOptions' => $domainOptions,
            'tagOptions' => $tagOptions,
            'rangeOptions' => self::RANGES,
        ]);
    }

    protected function baseQuery(Organization $organization, CarbonInterface $since, string $domain, string $tag): HasMany
    {
        $query = $organization->messages()
            ->where('direction', 'outbound')
            ->where('created_at', '>=', $since);

        if ($domain !== '') {
            $query->whereRaw('lower(from_email) like ?', ['%@'.$domain]);
        }

        if ($tag !== '') {
            $query->whereJsonContains('tags', $tag);
        }

        return $query;
    }

    protected function delivered(HasMany|Builder $query): HasMany|Builder
    {
        return $query->where(fn ($q) => $q->whereIn('status', ['delivered', 'complained'])->orWhereNotNull('meta->delivered_at'));
    }

    protected function complained(HasMany|Builder $query): HasMany|Builder
    {
        return $query->where(fn ($q) => $q->where('status', 'complained')->orWhereNotNull('meta->complained_at'));
    }

    /**
     * @return array<string, int>
     */
    protected function byDay(HasMany|Builder $query): array
    {
        return $query
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->pluck('total', 'day')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    protected function rate(int $part, int $whole): float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 1) : 0.0;
    }

    /**
     * Distinct string tags used on outbound mail in the window (capped scan).
     *
     * @return list<string>
     */
    protected function tagOptions(Organization $organization, CarbonInterface $since): array
    {
        return $organization->messages()
            ->where('direction', 'outbound')
            ->where('created_at', '>=', $since)
            ->whereNotNull('tags')
            ->latest('id')
            ->limit(2000)
            ->pluck('tags')
            ->flatten()
            ->filter(fn ($t) => is_string($t) && $t !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function broadcastStats(Organization $organization, BroadcastService $service, CarbonInterface $since, string $domain): array
    {
        $query = $organization->broadcasts()
            ->where('recipient_count', '>', 0)
            ->where(fn ($q) => $q->where('sent_at', '>=', $since)->orWhere('queued_at', '>=', $since))
            ->latest('id')
            ->limit(20);

        if ($domain !== '') {
            $column = $query->getQuery()->getGrammar()->wrap('from');
            $query->where(fn ($q) => $q->whereRaw("lower({$column}) like ?", ['%@'.$domain])
                ->orWhereRaw("lower({$column}) like ?", ['%@'.$domain.'>']));
        }

        return $query->get()->map(function (Broadcast $broadcast) use ($service) {
            $counts = $service->counts($broadcast);

            return [
                'id' => $broadcast->id,
                'name' => $broadcast->name,
                'status' => $broadcast->status,
                'sent_at' => ($broadcast->sent_at ?? $broadcast->queued_at)?->toDateString(),
                ...$counts,
                'delivery_rate' => $this->rate($counts['delivered'], $counts['sent']),
                'open_rate' => $this->rate($counts['opened'], $counts['delivered']),
                'click_rate' => $this->rate($counts['clicked'] ?? 0, $counts['delivered']),
            ];
        })->values()->all();
    }
}
