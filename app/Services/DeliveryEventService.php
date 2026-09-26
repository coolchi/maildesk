<?php

namespace App\Services;

use App\Jobs\DispatchWebhook;
use App\Mail\Events\Contracts\DeliveryEventDriver;
use App\Mail\Events\DeliveryEvent;
use App\Mail\Events\ResendDeliveryEventDriver;
use App\Models\Message;
use App\Models\Suppression;
use Illuminate\Support\Facades\DB;

/**
 * Applies provider delivery events to outbound messages: status, event
 * history, open/click tracking, automatic suppression and outgoing webhooks.
 */
class DeliveryEventService
{
    /** @var array<string, class-string<DeliveryEventDriver>> Add providers here. */
    public const DRIVERS = [
        'resend' => ResendDeliveryEventDriver::class,
    ];

    /** Higher ranks win; a late "delivered" never overwrites "bounced". */
    protected const RANK = [
        'queued' => 0,
        'scheduled' => 0,
        'sent' => 1,
        'delivered' => 2,
        'bounced' => 3,
        'complained' => 3,
    ];

    protected const MAX_EVENTS = 50;

    public static function supports(string $driver): bool
    {
        return isset(self::DRIVERS[$driver]);
    }

    public function driver(string $driver): ?DeliveryEventDriver
    {
        return self::supports($driver) ? app(self::DRIVERS[$driver]) : null;
    }

    /**
     * @return array{status: 'processed'|'duplicate'|'unmatched', message: ?Message, suppressed: list<string>}
     */
    public function handle(DeliveryEvent $event): array
    {
        $result = DB::transaction(function () use ($event) {
            $message = Message::query()
                ->where('direction', 'outbound')
                ->where('provider', $event->provider)
                ->where('provider_message_id', $event->providerMessageId)
                ->lockForUpdate()
                ->first();

            if ($message === null) {
                return ['status' => 'unmatched', 'message' => null, 'suppressed' => []];
            }

            $meta = (array) ($message->meta ?? []);
            $events = (array) ($meta['events'] ?? []);

            if ($event->eventId !== null && collect($events)->contains('id', $event->eventId)) {
                return ['status' => 'duplicate', 'message' => $message, 'suppressed' => []];
            }

            $at = ($event->occurredAt ?? now()->toImmutable())->toIso8601String();

            $events[] = array_filter([
                'id' => $event->eventId,
                'type' => $event->type,
                'at' => $at,
                'recipients' => $event->recipients ?: null,
                'bounce_type' => $event->bounceType,
                'reason' => $event->reason,
                ...$event->details,
            ], fn ($value) => $value !== null);
            $meta['events'] = array_slice($events, -self::MAX_EVENTS);

            $newStatus = null;

            switch ($event->type) {
                case DeliveryEvent::DELIVERED:
                    $meta['delivered_at'] ??= $at;
                    $newStatus = 'delivered';
                    break;
                case DeliveryEvent::BOUNCED:
                    $meta['bounce'] = array_filter([
                        'type' => $event->bounceType,
                        'reason' => $event->reason,
                        'at' => $at,
                    ]);
                    // Soft bounces are retried by the provider; keep the status.
                    $newStatus = $event->isHardBounce() ? 'bounced' : null;
                    break;
                case DeliveryEvent::COMPLAINED:
                    $meta['complained_at'] = $at;
                    $newStatus = 'complained';
                    break;
                case DeliveryEvent::OPENED:
                    $meta['open_count'] = (int) ($meta['open_count'] ?? 0) + 1;
                    $meta['first_opened_at'] ??= $at;
                    $meta['last_opened_at'] = $at;
                    break;
                case DeliveryEvent::CLICKED:
                    $meta['click_count'] = (int) ($meta['click_count'] ?? 0) + 1;
                    $meta['first_clicked_at'] ??= $at;
                    $meta['last_clicked_at'] = $at;
                    break;
            }

            $attributes = ['meta' => $meta];

            if ($newStatus !== null && (self::RANK[$newStatus] ?? 0) >= (self::RANK[$message->status] ?? 0)) {
                $attributes['status'] = $newStatus;
            }

            $message->forceFill($attributes)->save();

            $suppressed = [];

            if ($event->isHardBounce() || $event->type === DeliveryEvent::COMPLAINED) {
                $suppressed = $this->suppress($message, $event);
            }

            return ['status' => 'processed', 'message' => $message, 'suppressed' => $suppressed];
        });

        if ($result['status'] === 'processed') {
            $message = $result['message'];

            DispatchWebhook::dispatch($message->organization_id, 'email.'.$event->type, [
                'id' => $message->uuid,
                'status' => $message->status,
                'to' => $event->recipients ?: $message->to,
                'subject' => $message->subject,
                'occurred_at' => ($event->occurredAt ?? now())->toIso8601String(),
                'bounce_type' => $event->bounceType,
                'reason' => $event->reason,
            ]);
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    protected function suppress(Message $message, DeliveryEvent $event): array
    {
        $recipients = $event->recipients ?: $this->addresses($message->to);
        $source = $event->type === DeliveryEvent::COMPLAINED ? 'complaint' : 'bounce';
        $reason = $event->type === DeliveryEvent::COMPLAINED
            ? 'Spam complaint'
            : trim('Hard bounce'.($event->reason ? ': '.$event->reason : ''));

        foreach ($recipients as $email) {
            Suppression::query()->firstOrCreate(
                ['organization_id' => $message->organization_id, 'email' => $email],
                ['reason' => mb_substr($reason, 0, 255), 'source' => $source],
            );
        }

        return $recipients;
    }

    /**
     * @return list<string>
     */
    protected function addresses(mixed $value): array
    {
        return array_values(array_filter(array_map(function ($entry) {
            $email = is_array($entry) ? ($entry['email'] ?? $entry['address'] ?? null) : $entry;

            return is_string($email) && $email !== '' ? strtolower(trim($email)) : null;
        }, (array) $value)));
    }
}
