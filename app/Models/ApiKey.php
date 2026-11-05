<?php

namespace App\Models;

use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'user_id',
        'name',
        'key_prefix',
        'key_hash',
        'abilities',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{plain: string, model: self}
     */
    public static function issue(Organization $organization, string $name, ?User $user = null, array $abilities = ['*'], ?\DateTimeInterface $expiresAt = null): array
    {
        $plain = 'md_'.Str::random(40);
        $prefix = substr($plain, 0, 12);

        $model = static::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user?->id,
            'name' => $name,
            'key_prefix' => $prefix,
            'key_hash' => hash('sha256', $plain),
            'abilities' => array_values($abilities),
            'expires_at' => $expiresAt,
        ]);

        return ['plain' => $plain, 'model' => $model];
    }

    /**
     * Replace this key with a new secret that has the same name, abilities
     * and validity period. The old key is revoked immediately (revoked_at
     * is set; the row is kept so it shows as Revoked in the list).
     *
     * @return array{plain: string, model: self}
     */
    public function rotate(?User $user = null): array
    {
        $organization = $this->organization;

        $expiresAt = null;
        if ($this->expires_at !== null && $this->created_at !== null) {
            $expiresAt = now()->addSeconds(max(60, (int) abs($this->created_at->diffInSeconds($this->expires_at))));
        }

        return DB::transaction(function () use ($organization, $user, $expiresAt) {
            $issued = static::issue($organization, $this->name, $user ?? $this->user, $this->abilities ?? ['*'], $expiresAt);
            $this->revoke();

            return $issued;
        });
    }

    /**
     * Permanently disable this key. The row is kept for the audit trail.
     */
    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->forceFill(['revoked_at' => now()])->save();
        }
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Full-access keys (*) can do anything; others only what they list.
     */
    public function can(string $ability): bool
    {
        $abilities = $this->abilities ?? ['*'];

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    /**
     * The single sending domain this key is limited to, or null for all.
     */
    public function scopedDomain(): ?string
    {
        foreach ($this->abilities ?? [] as $ability) {
            if (is_string($ability) && str_starts_with($ability, 'domain:')) {
                return strtolower(substr($ability, 7));
            }
        }

        return null;
    }

    public function matches(string $plain): bool
    {
        return hash_equals($this->key_hash, hash('sha256', $plain));
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(?string $timezone = null): array
    {
        $abilities = $this->abilities ?? ['*'];
        $permission = in_array('emails:send', $abilities, true) && ! in_array('*', $abilities, true)
            ? 'Sending access'
            : 'Full access';

        $domain = 'All domains';
        foreach ($abilities as $ability) {
            if (is_string($ability) && str_starts_with($ability, 'domain:')) {
                $domain = substr($ability, 7);
                break;
            }
        }

        $tz = $timezone ?? $this->organization?->getTimezone() ?? 'Africa/Lagos';

        return [
            'id' => $this->id,
            'name' => $this->name,
            'prefix' => $this->key_prefix,
            'permission' => $permission,
            'domain' => $domain,
            'created' => $this->created_at?->diffForHumans() ?? '',
            'last_used' => $this->last_used_at?->diffForHumans() ?? 'Never',
            'expires' => $this->expires_at?->timezone($tz)->toFormattedDateString() ?? 'Never',
            'expires_at' => $this->expires_at?->timezone($tz)->toDateString(),
            'expired' => $this->isExpired(),
            'revoked' => $this->isRevoked(),
            'revoked_at' => $this->revoked_at?->timezone($tz)->toFormattedDateString(),
        ];
    }
}
