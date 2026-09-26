<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Organization;
use App\Models\Segment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Resolve which contacts belong to a segment: manual pivot membership,
 * dynamic rules, or both (attached contacts that also match the rules).
 */
class SegmentMembership
{
    /**
     * Contacts in the organization that currently match the segment.
     *
     * @return Builder<Contact>
     */
    public function query(Segment $segment): Builder
    {
        $organization = $segment->organization ?? Organization::query()->findOrFail($segment->organization_id);

        $rules = $this->normalizeRules($segment->rules);
        $hasRules = $rules !== [];
        $hasManual = $segment->contacts()->exists();

        $query = Contact::query()
            ->where('organization_id', $organization->id)
            ->orderBy('id');

        if (! $hasManual && ! $hasRules) {
            return $query->whereRaw('0 = 1');
        }

        if ($hasManual) {
            $query->whereHas('segments', fn (Builder $q) => $q->where('segments.id', $segment->id));
        }

        if ($hasRules) {
            $this->applyRules($query, $rules);
        }

        return $query;
    }

    /**
     * Count members without loading them.
     */
    public function count(Segment $segment): int
    {
        return $this->query($segment)->count();
    }

    /**
     * Human-readable summary of the rules for the UI.
     *
     * @param  array<int, array<string, mixed>>|null  $rules
     */
    public function summarize(?array $rules): string
    {
        $normalized = $this->normalizeRules($rules);

        if ($normalized === []) {
            return 'Manual membership';
        }

        return collect($normalized)
            ->map(function (array $rule): string {
                $field = (string) ($rule['field'] ?? '');
                $op = (string) ($rule['op'] ?? 'eq');
                $value = (string) ($rule['value'] ?? '');

                return match ($field) {
                    'meta.status', 'status' => "status {$op} {$value}",
                    'email_domain' => "email domain {$op} {$value}",
                    default => "{$field} {$op} {$value}",
                };
            })
            ->implode(' AND ');
    }

    /**
     * @return array<int, array{field: string, op: string, value: string}>
     */
    public function normalizeRules(mixed $rules): array
    {
        if (! is_array($rules)) {
            return [];
        }

        // Support { "all": [ ... ] } wrappers from future UI shapes.
        if (isset($rules['all']) && is_array($rules['all'])) {
            $rules = $rules['all'];
        }

        $normalized = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $field = Str::lower(trim((string) ($rule['field'] ?? '')));
            $op = Str::lower(trim((string) ($rule['op'] ?? $rule['operator'] ?? 'eq')));
            $value = trim((string) ($rule['value'] ?? ''));

            if ($field === '' || $value === '') {
                continue;
            }

            if (! in_array($field, ['meta.status', 'status', 'email_domain'], true)) {
                continue;
            }

            if (! in_array($op, ['eq', 'equals', 'contains'], true)) {
                continue;
            }

            $normalized[] = [
                'field' => $field === 'status' ? 'meta.status' : $field,
                'op' => $op === 'equals' ? 'eq' : $op,
                'value' => $value,
            ];
        }

        return $normalized;
    }

    /**
     * @param  Builder<Contact>  $query
     * @param  array<int, array{field: string, op: string, value: string}>  $rules
     */
    protected function applyRules(Builder $query, array $rules): void
    {
        foreach ($rules as $rule) {
            match ($rule['field']) {
                'meta.status' => $this->applyStatusRule($query, $rule['op'], $rule['value']),
                'email_domain' => $this->applyEmailDomainRule($query, $rule['op'], $rule['value']),
                default => null,
            };
        }
    }

    /**
     * @param  Builder<Contact>  $query
     */
    protected function applyStatusRule(Builder $query, string $op, string $value): void
    {
        $value = Str::lower($value);

        if ($op === 'contains') {
            $query->where(function (Builder $inner) use ($value) {
                $inner->where('meta->status', 'like', '%'.$value.'%');
                if (str_contains('subscribed', $value)) {
                    $inner->orWhere(function (Builder $subscribed) {
                        $subscribed->whereNull('unsubscribed_at')
                            ->where(function (Builder $status) {
                                $status->whereNull('meta->status')
                                    ->orWhere('meta->status', 'subscribed');
                            });
                    });
                }
                if (str_contains('unsubscribed', $value)) {
                    $inner->orWhereNotNull('unsubscribed_at')
                        ->orWhere('meta->status', 'unsubscribed');
                }
            });

            return;
        }

        if ($value === 'unsubscribed') {
            $query->where(function (Builder $inner) {
                $inner->whereNotNull('unsubscribed_at')
                    ->orWhere('meta->status', 'unsubscribed');
            });

            return;
        }

        // Default / subscribed: not unsubscribed by timestamp or meta.
        $query->whereNull('unsubscribed_at')
            ->where(function (Builder $inner) {
                $inner->whereNull('meta->status')
                    ->orWhere('meta->status', 'subscribed');
            });
    }

    /**
     * @param  Builder<Contact>  $query
     */
    protected function applyEmailDomainRule(Builder $query, string $op, string $value): void
    {
        $needle = Str::lower($value);

        if ($op === 'eq') {
            // Domain equals value (case-insensitive). Portable across SQLite/MySQL.
            $query->whereRaw('lower(email) like ?', ['%@'.$needle]);

            return;
        }

        $query->whereRaw('lower(email) like ?', ['%@%'.$needle.'%']);
    }
}
