<?php

namespace App\Models;

use Database\Factories\BroadcastFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Broadcast extends Model
{
    /** @use HasFactory<BroadcastFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'subject',
        'html',
        'status',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

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
            'name' => $this->name,
            'subject' => $this->subject,
            'status' => $this->status,
            'audience' => 'All subscribed',
            'recipients' => 0,
            'open_rate' => '—',
            'sent' => $this->sent_at?->diffForHumans()
                ?? ($this->status === 'draft' ? '—' : ($this->updated_at?->diffForHumans() ?? '—')),
        ];
    }
}
