<?php

namespace App\Models;

use App\Support\PlanNairaPrice;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $fillable = [
        'key',
        'product',
        'name',
        'price',
        'price_kobo',
        'interval',
        'emails',
        'contacts',
        'seats',
        'featured',
        'features',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'price_kobo' => 'integer',
            'emails' => 'integer',
            'contacts' => 'integer',
            'seats' => 'integer',
            'featured' => 'boolean',
            'features' => 'array',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->key,
            'dbId' => $this->id,
            'product' => $this->product,
            'name' => $this->name,
            'price' => $this->price,
            'interval' => $this->interval,
            'emails' => $this->emails,
            'contacts' => $this->contacts,
            'seats' => $this->seats,
            'featured' => $this->featured,
            'features' => $this->features ?? [],
            'price_kobo' => $this->price_kobo,
            'price_ngn' => PlanNairaPrice::toNaira($this->price_kobo),
            'monipay_payable' => PlanNairaPrice::payableViaMonipay($this),
            'subscriptions_count' => $this->subscriptions_count ?? $this->subscriptions()->count(),
        ];
    }
}
