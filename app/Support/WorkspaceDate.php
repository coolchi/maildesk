<?php

namespace App\Support;

use App\Models\Organization;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Format dates in the workspace's configured timezone.
 *
 * Storage remains UTC; this helper only applies the timezone for display.
 */
class WorkspaceDate
{
    public const FORMAT_FULL = 'M j, Y g:i A';

    public const FORMAT_SHORT = 'M j, g:i A';

    public const FORMAT_DATE = 'M j, Y';

    public const FORMAT_DATETIME = 'Y-m-d H:i:s';

    /**
     * Format a timestamp in the workspace's timezone.
     */
    public static function format(
        DateTimeInterface|string|null $date,
        Organization $organization,
        string $format = self::FORMAT_FULL,
    ): ?string {
        if ($date === null) {
            return null;
        }

        $carbon = $date instanceof DateTimeInterface
            ? Carbon::instance($date)
            : Carbon::parse($date);

        return $carbon->timezone($organization->getTimezone())->format($format);
    }

    /**
     * Format as full datetime: "Sep 29, 2026 5:34 AM"
     */
    public static function full(DateTimeInterface|string|null $date, Organization $organization): ?string
    {
        return self::format($date, $organization, self::FORMAT_FULL);
    }

    /**
     * Format as short datetime: "Sep 29, 5:34 AM"
     */
    public static function short(DateTimeInterface|string|null $date, Organization $organization): ?string
    {
        return self::format($date, $organization, self::FORMAT_SHORT);
    }

    /**
     * Format as date only: "Sep 29, 2026"
     */
    public static function date(DateTimeInterface|string|null $date, Organization $organization): ?string
    {
        return self::format($date, $organization, self::FORMAT_DATE);
    }

    /**
     * Format as ISO-like datetime: "2026-09-29 05:34:00"
     */
    public static function datetime(DateTimeInterface|string|null $date, Organization $organization): ?string
    {
        return self::format($date, $organization, self::FORMAT_DATETIME);
    }

    /**
     * Parse a date string that was entered in the workspace's timezone and convert to UTC.
     */
    public static function parseToUtc(string $date, Organization $organization): Carbon
    {
        return Carbon::parse($date, $organization->getTimezone())->utc();
    }
}
