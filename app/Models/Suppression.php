<?php

namespace App\Models;

use Database\Factories\SuppressionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Suppression extends Model
{
    /** @use HasFactory<SuppressionFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'email',
        'reason',
        'source',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'reason' => $this->reason ?: 'Manual suppression',
            'source' => $this->source,
            'created' => $this->created_at?->diffForHumans() ?? '',
        ];
    }
}
