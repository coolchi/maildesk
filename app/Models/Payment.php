<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ABANDONED = 'abandoned';

    protected $fillable = [
        'organization_id',
        'user_id',
        'plan_id',
        'plan_key',
        'subscription_id',
        'provider',
        'reference',
        'provider_reference',
        'access_code',
        'trans_id',
        'amount',
        'currency',
        'status',
        'channel',
        'paid_at',
        'fulfilled_at',
        'verify_payload',
        'meta',
    ];

    protected $hidden = ['access_code', 'verify_payload'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'verify_payload' => 'array',
            'meta' => 'array',
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

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isFulfilled(): bool
    {
        return $this->fulfilled_at !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toBillingArray(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->created_at?->format('M j, Y'),
            'plan' => $this->plan?->name ?? $this->plan_key,
            'amount' => $this->amount,
            'amount_formatted' => '₦'.number_format($this->amount / 100, 2),
            'currency' => $this->currency,
            'status' => $this->status,
            'reference' => $this->reference,
        ];
    }
}
