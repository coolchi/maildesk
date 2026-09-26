<?php

namespace App\Models;

use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    protected $fillable = [
        'key',
        'organization_id',
        'plan_id',
        'plan_name',
        'product',
        'status',
        'price',
        'renews_at',
        'current_period_ends_at',
        'seats',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'seats' => 'integer',
            'current_period_ends_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->key,
            'dbId' => $this->id,
            'accountId' => $this->organization_id,
            'account' => $this->organization?->name,
            'planId' => $this->plan?->key,
            'plan' => $this->plan_name,
            'product' => $this->product,
            'status' => $this->status,
            'price' => $this->price,
            'renews' => $this->renews_at ?? '—',
            'seats' => $this->seats,
        ];
    }
}
