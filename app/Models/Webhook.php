<?php

namespace App\Models;

use Database\Factories\WebhookFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Webhook extends Model
{
    /** @use HasFactory<WebhookFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'url',
        'secret',
        'events',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public static function generateSecret(): string
    {
        return 'whsec_'.Str::lower(Str::random(32));
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(bool $includeSecret = false): array
    {
        $latest = $this->relationLoaded('deliveries')
            ? $this->deliveries->sortByDesc('id')->first()
            : $this->deliveries()->latest('id')->first();

        $lastDelivery = 'Never';
        if ($latest) {
            $when = ($latest->delivered_at ?? $latest->created_at)?->diffForHumans() ?? '';
            $lastDelivery = match ($latest->status) {
                'success' => "Success · {$when}",
                'failed' => 'Failed'.($latest->response_status ? " · {$latest->response_status}" : '')." · {$when}",
                default => "Pending · {$when}",
            };
        }

        $payload = [
            'id' => $this->id,
            'endpoint' => $this->url,
            'events' => $this->events ?? [],
            'status' => $this->is_active ? 'enabled' : 'disabled',
            'created' => $this->created_at?->diffForHumans() ?? '',
            'last_delivery' => $lastDelivery,
            'secret_masked' => $this->secret ? '••••'.substr($this->secret, -6) : null,
        ];

        if ($includeSecret) {
            $payload['secret'] = $this->secret;
        }

        return $payload;
    }
}
