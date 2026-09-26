<?php

namespace App\Mail\Events;

use Carbon\CarbonImmutable;

/**
 * Provider-neutral delivery event (delivered, bounced, complained, opened).
 */
final class DeliveryEvent
{
    public const DELIVERED = 'delivered';

    public const BOUNCED = 'bounced';

    public const COMPLAINED = 'complained';

    public const OPENED = 'opened';

    /**
     * @param  list<string>  $recipients
     * @param  array<string, mixed>  $details  Extra provider details worth keeping.
     */
    public function __construct(
        public readonly string $type,
        public readonly string $provider,
        public readonly string $providerMessageId,
        public readonly ?string $eventId = null,
        public readonly array $recipients = [],
        public readonly ?CarbonImmutable $occurredAt = null,
        public readonly ?string $bounceType = null,
        public readonly ?string $reason = null,
        public readonly array $details = [],
    ) {}

    /**
     * Soft/transient bounces are retried by the provider and must not suppress.
     */
    public function isHardBounce(): bool
    {
        return $this->type === self::BOUNCED
            && ! in_array(strtolower((string) $this->bounceType), ['transient', 'soft'], true);
    }
}
