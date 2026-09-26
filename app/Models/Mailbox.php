<?php

namespace App\Models;

use Database\Factories\MailboxFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mailbox extends Model
{
    /** @use HasFactory<MailboxFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'user_id',
        'domain_id',
        'email',
        'display_name',
        'type',
        'role',
        'status',
        'inbox',
        'transactional',
        'marketing',
        'send_limit',
        'signature',
    ];

    protected function casts(): array
    {
        return [
            'inbox' => 'boolean',
            'transactional' => 'boolean',
            'marketing' => 'boolean',
            'send_limit' => 'integer',
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

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(?User $actor = null, bool $actorIsTeam = false): array
    {
        $sent = $this->relationLoaded('messages')
            ? $this->messages->where('direction', 'outbound')->count()
            : $this->messages()->where('direction', 'outbound')->count();
        $inboxCount = $this->relationLoaded('messages')
            ? $this->messages->where('direction', 'inbound')->count()
            : $this->messages()->where('direction', 'inbound')->count();

        $canImpersonate = $actorIsTeam
            && $this->user_id
            && $this->status === 'active'
            && $actor
            && (int) $this->user_id !== (int) $actor->id;

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->display_name ?: $this->email,
            'email' => $this->email,
            'role' => $this->role ?: 'staff',
            'status' => $this->status ?: 'active',
            'inbox' => (bool) $this->inbox,
            'transactional' => (bool) $this->transactional,
            'marketing' => (bool) $this->marketing,
            'has_login' => $this->user_id !== null,
            'can_impersonate' => $canImpersonate,
            'usage' => [
                'sent' => $sent,
                'limit' => $this->send_limit ?? 0,
                'inbox' => $inboxCount,
            ],
            'last_active' => $this->updated_at?->diffForHumans() ?? '',
            'created' => $this->created_at?->format('M j, Y') ?? '',
        ];
    }
}
