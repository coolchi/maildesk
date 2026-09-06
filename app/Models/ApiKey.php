<?php

namespace App\Models;

use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
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
    public static function issue(Organization $organization, string $name, ?User $user = null, array $abilities = ['*']): array
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
        ]);

        return ['plain' => $plain, 'model' => $model];
    }

    public function matches(string $plain): bool
    {
        return hash_equals($this->key_hash, hash('sha256', $plain));
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
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

        return [
            'id' => $this->id,
            'name' => $this->name,
            'prefix' => $this->key_prefix,
            'permission' => $permission,
            'domain' => $domain,
            'created' => $this->created_at?->diffForHumans() ?? '',
            'last_used' => $this->last_used_at?->diffForHumans() ?? 'Never',
        ];
    }
}
