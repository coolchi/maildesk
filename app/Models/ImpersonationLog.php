<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImpersonationLog extends Model
{
    protected $fillable = [
        'admin_id',
        'user_id',
        'organization_id',
        'reason',
        'ip',
        'user_agent',
        'started_at',
        'ended_at',
        'end_reason',
        'denied_reason',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ImpersonationAction::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'admin' => $this->admin?->name ?? 'Deleted admin',
            'user' => $this->user?->name,
            'user_email' => $this->user?->email,
            'reason' => $this->reason,
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'end_reason' => $this->end_reason,
            'denied_reason' => $this->denied_reason,
            'ip' => $this->ip,
            'actions_count' => (int) ($this->actions_count ?? $this->actions()->count()),
            'blocked_count' => (int) ($this->blocked_actions_count ?? $this->actions()->where('blocked', true)->count()),
        ];
    }
}
