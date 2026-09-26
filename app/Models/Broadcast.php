<?php

namespace App\Models;

use Database\Factories\BroadcastFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'audience',
        'from',
        'recipient_count',
        'queued_at',
        'scheduled_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'queued_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
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
            'audience' => $this->audienceLabel(),
            'recipients' => (int) $this->recipient_count,
            'open_rate' => '—',
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'sent' => $this->scheduled_at && $this->status === 'scheduled'
                ? 'Scheduled '.$this->scheduled_at->diffForHumans()
                : ($this->sent_at?->diffForHumans()
                    ?? ($this->status === 'draft' ? '—' : ($this->updated_at?->diffForHumans() ?? '—'))),
        ];
    }

    public function audienceLabel(): string
    {
        $audience = (string) ($this->audience ?? 'all');

        if ($audience === 'all' || $audience === '') {
            return 'All subscribed';
        }

        if (ctype_digit($audience)) {
            return Segment::query()->whereKey((int) $audience)->value('name') ?? 'Segment';
        }

        if (str_starts_with($audience, 'group:')) {
            $group = GroupAddress::query()->whereKey((int) substr($audience, 6))->first(['name', 'email']);

            return $group ? "Group: {$group->name} ({$group->email})" : 'Group';
        }

        return $audience;
    }
}
