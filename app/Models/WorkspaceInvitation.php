<?php

namespace App\Models;

use App\Services\TenantResolver;
use Database\Factories\WorkspaceInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class WorkspaceInvitation extends Model
{
    /** @use HasFactory<WorkspaceInvitationFactory> */
    use HasFactory;

    public const ROLES = ['admin', 'member'];

    protected $fillable = [
        'organization_id',
        'email',
        'role',
        'token',
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WorkspaceInvitation $invitation): void {
            if (empty($invitation->token)) {
                $invitation->token = Str::random(64);
            }

            if ($invitation->email !== null) {
                $invitation->email = Str::lower(trim($invitation->email));
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at instanceof Carbon && $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * Absolute accept URL on the workspace host.
     */
    public function acceptUrl(): string
    {
        $this->loadMissing('organization');

        $path = route('invitations.show', ['token' => $this->token], absolute: false);
        $base = rtrim(app(TenantResolver::class)->workspaceUrl($this->organization, '/'), '/');

        return $base.$path;
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role,
            'expires_at' => $this->expires_at?->timezone(config('app.timezone'))->format('M j, Y'),
            'invited_by' => $this->inviter?->name,
            'status' => $this->isAccepted()
                ? 'accepted'
                : ($this->isExpired() ? 'expired' : 'pending'),
        ];
    }
}
