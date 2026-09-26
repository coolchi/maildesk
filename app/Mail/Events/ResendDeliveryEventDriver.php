<?php

namespace App\Mail\Events;

use App\Mail\Events\Contracts\DeliveryEventDriver;
use App\Mail\Inbound\ResendInboundDriver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

/**
 * Resend email.delivered / email.bounced / email.complained / email.opened / email.clicked.
 * Signed with the same Svix secret as inbound (RESEND_WEBHOOK_SECRET).
 */
class ResendDeliveryEventDriver implements DeliveryEventDriver
{
    protected const TYPES = [
        'email.delivered' => DeliveryEvent::DELIVERED,
        'email.bounced' => DeliveryEvent::BOUNCED,
        'email.complained' => DeliveryEvent::COMPLAINED,
        'email.opened' => DeliveryEvent::OPENED,
        'email.clicked' => DeliveryEvent::CLICKED,
    ];

    public function __construct(private readonly ResendInboundDriver $signature) {}

    public function isConfigured(): bool
    {
        return $this->signature->isConfigured();
    }

    public function verify(Request $request): bool
    {
        return $this->signature->verify($request);
    }

    public function parse(Request $request): ?DeliveryEvent
    {
        $payload = $request->json()->all();
        $type = self::TYPES[$payload['type'] ?? ''] ?? null;
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $emailId = (string) ($data['email_id'] ?? '');

        if ($type === null || $emailId === '') {
            return null;
        }

        $bounce = is_array($data['bounce'] ?? null) ? $data['bounce'] : [];

        return new DeliveryEvent(
            type: $type,
            provider: 'resend',
            providerMessageId: $emailId,
            eventId: $request->header('svix-id') ?: null,
            recipients: array_values(array_filter(array_map(
                fn ($to) => is_string($to) ? strtolower(trim($to)) : null,
                (array) ($data['to'] ?? []),
            ))),
            occurredAt: $this->time($payload['created_at'] ?? $data['created_at'] ?? null),
            bounceType: isset($bounce['type']) ? (string) $bounce['type'] : null,
            reason: isset($bounce['message']) ? (string) $bounce['message'] : null,
            details: array_filter([
                'bounce_sub_type' => $bounce['subType'] ?? null,
                'subject' => $data['subject'] ?? null,
                'ip' => $data['open']['ipAddress'] ?? $data['click']['ipAddress'] ?? null,
                'user_agent' => $data['open']['userAgent'] ?? $data['click']['userAgent'] ?? null,
                'link' => $data['click']['link'] ?? null,
            ], fn ($value) => $value !== null),
        );
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        try {
            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
