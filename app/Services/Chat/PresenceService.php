<?php

namespace App\Services\Chat;

use App\Models\User;
use Carbon\CarbonInterface;

class PresenceService
{
    /** A member stays online for this long after the last heartbeat. */
    public const ONLINE_FOR_SECONDS = 180;

    /** Skip writing last_seen_at again within this window. */
    public const TOUCH_EVERY_SECONDS = 45;

    public function touch(User $user): void
    {
        $seen = $user->last_seen_at;
        if ($seen !== null && $seen->greaterThan(now()->subSeconds(self::TOUCH_EVERY_SECONDS))) {
            return;
        }

        $user->forceFill(['last_seen_at' => now()])->save();
    }

    public function isOnline(?CarbonInterface $lastSeenAt): bool
    {
        return $lastSeenAt !== null && $lastSeenAt->greaterThan(now()->subSeconds(self::ONLINE_FOR_SECONDS));
    }

    /**
     * @return array{online: bool, last_seen_at: ?string}
     */
    public function forUser(?User $user): array
    {
        $seen = $user?->last_seen_at;

        return [
            'online' => $this->isOnline($seen),
            'last_seen_at' => $seen?->toIso8601String(),
        ];
    }
}
