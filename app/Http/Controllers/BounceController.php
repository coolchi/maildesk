<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Suppression;
use App\Services\WorkspaceAccess;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lists outbound emails that bounced: hard bounces (status "bounced") and
 * soft bounces (a bounce was recorded but the provider kept retrying).
 */
class BounceController extends Controller
{
    public function __construct(public WorkspaceAccess $access) {}

    public function index(Request $request): Response
    {
        $organization = CurrentOrganization::from($request);
        $user = $request->user();

        $type = in_array($request->string('type')->toString(), ['hard', 'soft'], true)
            ? $request->string('type')->toString()
            : 'all';
        $search = trim($request->string('q')->toString());

        $base = fn (): Builder => $this->access->scopeMailData($organization->messages(), $user, $organization)
            ->getQuery()
            ->where('direction', 'outbound')
            ->where(fn (Builder $q) => $q->where('status', 'bounced')->orWhereNotNull('meta->bounce'));

        $hard = fn (Builder $q) => $q->where(fn (Builder $w) => $w
            ->where('status', 'bounced')
            ->orWhereRaw("lower(coalesce(json_extract(meta, '$.bounce.type'), '')) in ('hard', 'permanent')"));

        $query = $base()
            ->when($type === 'hard', $hard)
            ->when($type === 'soft', fn (Builder $q) => $q->whereNot($hard))
            ->when($search !== '', function (Builder $q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(fn (Builder $w) => $w
                    ->where('subject', 'like', $like)
                    ->orWhere('to', 'like', $like)
                    ->orWhere('meta->bounce->reason', 'like', $like));
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        $paginator = $query->paginate(25)->withQueryString();
        $messages = collect($paginator->items());

        $recipients = $messages
            ->flatMap(fn (Message $message) => self::addresses($message->to))
            ->unique()
            ->values();

        $suppressions = Suppression::query()
            ->where('organization_id', $organization->id)
            ->whereIn('email', $recipients)
            ->get()
            ->keyBy(fn (Suppression $suppression) => strtolower($suppression->email));

        $suppressedTotal = Suppression::query()
            ->where('organization_id', $organization->id)
            ->where('source', 'bounce')
            ->count();

        $tz = $organization->getTimezone();

        return Inertia::render('Bounced/Index', [
            'bounces' => $messages
                ->map(fn (Message $message) => self::present($message, $suppressions->all(), $tz))
                ->values()
                ->all(),
            'pagination' => [
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'prev_url' => $paginator->previousPageUrl(),
                'next_url' => $paginator->nextPageUrl(),
            ],
            'counts' => [
                'all' => $base()->count(),
                'hard' => $base()->where($hard)->count(),
                'soft' => $base()->whereNot($hard)->count(),
                'suppressed' => $suppressedTotal,
            ],
            'filters' => ['type' => $type, 'q' => $search],
        ]);
    }

    /**
     * @param  array<string, Suppression>  $suppressions
     * @return array<string, mixed>
     */
    private static function present(Message $message, array $suppressions, string $tz = 'Africa/Lagos'): array
    {
        $meta = (array) ($message->meta ?? []);
        $bounce = (array) ($meta['bounce'] ?? []);
        $rawType = strtolower((string) ($bounce['type'] ?? ''));
        $type = $message->status === 'bounced' || in_array($rawType, ['hard', 'permanent'], true) ? 'hard' : 'soft';

        $at = isset($bounce['at']) ? Carbon::parse($bounce['at']) : $message->updated_at;

        $recipients = self::addresses($message->to);
        $suppression = collect($recipients)
            ->map(fn (string $email) => $suppressions[$email] ?? null)
            ->filter()
            ->first();

        $events = collect((array) ($meta['events'] ?? []))
            ->map(fn (array $event) => [
                'type' => $event['type'] ?? 'event',
                'at' => isset($event['at']) ? Carbon::parse($event['at'])->timezone($tz)->format('M j, Y g:i:s A') : null,
                'bounce_type' => $event['bounce_type'] ?? null,
                'reason' => $event['reason'] ?? null,
                'recipients' => $event['recipients'] ?? null,
            ])
            ->values();

        // A bounce recorded only on the message (older data) still shows up in the history.
        if (! $events->contains('type', 'bounced') && ($bounce !== [] || $message->status === 'bounced')) {
            $events->push([
                'type' => 'bounced',
                'at' => isset($bounce['at']) ? Carbon::parse($bounce['at'])->timezone($tz)->format('M j, Y g:i:s A') : $message->updated_at?->timezone($tz)->format('M j, Y g:i:s A'),
                'bounce_type' => $bounce['type'] ?? null,
                'reason' => $bounce['reason'] ?? null,
                'recipients' => null,
            ]);
        }

        // Always start the history with the send itself.
        $sentAt = $message->sent_at ?? $message->created_at;
        $events->prepend([
            'type' => 'sent',
            'at' => $sentAt?->timezone($tz)->format('M j, Y g:i:s A'),
            'bounce_type' => null,
            'reason' => null,
            'recipients' => null,
        ]);

        return [
            'id' => $message->uuid,
            'to' => $recipients[0] ?? '',
            'to_count' => count($recipients),
            'subject' => $message->subject ?: '(no subject)',
            'status' => $message->status,
            'type' => $type,
            'provider_type' => $bounce['type'] ?? null,
            'reason' => $bounce['reason'] ?? data_get($meta, 'error') ?? 'No reason given by the provider.',
            'bounced_at' => $at?->timezone($tz)->format('M j, Y g:i A'),
            'bounced_ago' => $at?->diffForHumans() ?? '',
            'suppressed' => $suppression !== null,
            'suppression' => $suppression ? [
                'email' => $suppression->email,
                'reason' => $suppression->reason,
                'since' => $suppression->created_at?->timezone($tz)->format('M j, Y g:i A'),
            ] : null,
            'events' => $events->all(),
        ];
    }

    /**
     * @return list<string>
     */
    private static function addresses(mixed $value): array
    {
        return array_values(array_filter(array_map(function ($entry) {
            $email = is_array($entry) ? ($entry['email'] ?? $entry['address'] ?? null) : $entry;

            return is_string($email) && $email !== '' ? strtolower(trim($email)) : null;
        }, (array) $value)));
    }
}
