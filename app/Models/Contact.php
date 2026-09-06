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
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
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

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        $meta = $this->meta ?? [];

        return [
            'id' => $this->id,
            'email' => $this->email,
            'first_name' => $this->first_name ?? '',
            'last_name' => $this->last_name ?? '',
            'status' => $meta['status'] ?? 'subscribed',
            'created' => $this->created_at?->diffForHumans() ?? '',
        ];
    }
}
