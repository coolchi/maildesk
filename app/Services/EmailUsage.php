<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Live "emails sent in the last 30 days": outbound messages created in the
 * window, the same source SettingsController's usage tab counts. Replaces
 * the static organizations.emails_30d column in admin views.
 */
class EmailUsage
{
    public const WINDOW_DAYS = 30;

    /**
     * One grouped query for many organizations.
     *
     * @param  iterable<int>  $organizationIds
     * @return array<int, int> organization_id => count
     */
    public function counts(iterable $organizationIds): array
    {
        $ids = collect($organizationIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return DB::table('messages')
            ->select('organization_id', DB::raw('COUNT(*) as aggregate'))
            ->whereIn('organization_id', $ids)
            ->where('direction', 'outbound')
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->groupBy('organization_id')
            ->pluck('aggregate', 'organization_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    public function countFor(Organization $organization): int
    {
        return $this->counts([$organization->id])[$organization->id] ?? 0;
    }

    /**
     * Preload live counts onto a set of organizations (no DB writes).
     *
     * @param  Collection<int, Organization>  $organizations
     * @return Collection<int, Organization>
     */
    public function attach(Collection $organizations): Collection
    {
        $counts = $this->counts($organizations->pluck('id'));

        return $organizations->each(fn (Organization $org) => $org->setLiveEmails30d($counts[$org->id] ?? 0));
    }
}
