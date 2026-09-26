<?php

namespace App\Models;

use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'email',
        'first_name',
        'last_name',
        'company',
        'meta',
        'unsubscribed_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'unsubscribed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function segments(): BelongsToMany
    {
        return $this->belongsToMany(Segment::class);
    }

    public function isSubscribed(): bool
    {
        if ($this->unsubscribed_at !== null) {
            return false;
        }

        return ($this->meta['status'] ?? 'subscribed') !== 'unsubscribed';
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        $meta = $this->meta ?? [];
        $status = $this->isSubscribed() ? 'subscribed' : 'unsubscribed';
        $properties = is_array($meta['properties'] ?? null) ? $meta['properties'] : [];
        if (filled($this->company)) {
            $properties['company'] = $this->company;
        }

        return [
            'id' => $this->id,
            'email' => $this->email,
            'first_name' => $this->first_name ?? '',
            'last_name' => $this->last_name ?? '',
            'name' => trim(($this->first_name ?? '').' '.($this->last_name ?? '')),
            'company' => $this->company ?? '',
            'status' => $status,
            'properties' => $properties,
            'created' => $this->created_at?->diffForHumans() ?? '',
            'added' => $this->created_at?->diffForHumans() ?? '',
        ];
    }
}
