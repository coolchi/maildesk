<?php

namespace App\Models;

use App\Enums\ConversationType;
use App\Services\Chat\PresenceService;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'type',
        'name',
        'direct_key',
        'created_by',
        'last_message_preview',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
            'last_message_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toAppArray(User $viewer): array
    {
        $participants = $this->relationLoaded('participants')
            ? $this->participants
            : $this->participants()->with('user:id,name,last_seen_at')->get();

        $others = $participants->where('user_id', '!=', $viewer->id);
        $title = $this->type === ConversationType::Group
            ? ($this->name ?: 'Group')
            : ($others->first()?->user?->name ?? 'Chat');
        $presence = app(PresenceService::class);

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $title,
            'preview' => $this->last_message_preview,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'unread_count' => (int) ($this->unread_count ?? 0),
            'pinned' => $participants->firstWhere('user_id', $viewer->id)?->pinned_at !== null,
            'participants' => $participants->map(function (ConversationParticipant $participant) use ($presence) {
                $status = $presence->forUser($participant->user);

                return [
                    'id' => $participant->user_id,
                    'name' => $participant->user?->name ?? 'Former member',
                    'role' => $participant->role,
                    'last_read_at' => $participant->last_read_at?->toIso8601String(),
                    'last_delivered_at' => $participant->last_delivered_at?->toIso8601String(),
                    'online' => $status['online'],
                    'last_seen_at' => $status['last_seen_at'],
                ];
            })->values()->all(),
        ];
    }
}
