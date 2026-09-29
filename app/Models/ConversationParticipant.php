<?php

namespace App\Models;

use Database\Factories\ConversationParticipantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationParticipant extends Model
{
    /** @use HasFactory<ConversationParticipantFactory> */
    use HasFactory;

    public const RoleAdmin = 'admin';

    public const RoleMember = 'member';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'role',
        'last_read_at',
        'last_delivered_at',
        'pinned_at',
    ];

    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
            'last_delivered_at' => 'datetime',
            'pinned_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
