<?php

namespace App\Services\Chat;

use App\Enums\ConversationType;
use App\Events\ChatMessageSent;
use App\Jobs\SendChatPush;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConversationService
{
    /**
     * @return Collection<int, Conversation>
     */
    public function listFor(Organization $organization, User $user): Collection
    {
        return Conversation::query()
            ->where('organization_id', $organization->id)
            ->whereHas('participants', fn ($query) => $query->where('user_id', $user->id))
            ->with(['participants.user:id,name'])
            ->withCount(['messages as unread_count' => function ($query) use ($user) {
                $userId = (int) $user->id;
                $query->where(function ($query) use ($userId) {
                    $query->where('chat_messages.user_id', '!=', $userId)
                        ->orWhereNull('chat_messages.user_id');
                })->whereRaw(
                    "chat_messages.created_at > COALESCE((select last_read_at from conversation_participants where conversation_participants.conversation_id = chat_messages.conversation_id and conversation_participants.user_id = {$userId}), '1970-01-01 00:00:00')",
                );
            }])
            ->latest('last_message_at')
            ->limit(100)
            ->get()
            ->sort(function (Conversation $left, Conversation $right) use ($user) {
                $leftPinned = $left->participants->firstWhere('user_id', $user->id)?->pinned_at !== null;
                $rightPinned = $right->participants->firstWhere('user_id', $user->id)?->pinned_at !== null;

                if ($leftPinned !== $rightPinned) {
                    return $leftPinned ? -1 : 1;
                }

                return ($right->last_message_at?->timestamp ?? 0) <=> ($left->last_message_at?->timestamp ?? 0);
            })
            ->values();
    }

    public function findFor(Organization $organization, int $conversationId): Conversation
    {
        return Conversation::query()
            ->where('organization_id', $organization->id)
            ->with(['participants.user:id,name'])
            ->findOrFail($conversationId);
    }

    /**
     * @param  list<int>  $userIds
     */
    public function open(Organization $organization, User $user, ConversationType $type, array $userIds, ?string $name = null): Conversation
    {
        $memberIds = $this->memberIds($organization, $user, $userIds);

        if ($type === ConversationType::Direct) {
            return $this->openDirect($organization, $user, $memberIds[0]);
        }

        return $this->openGroup($organization, $user, $name, $memberIds);
    }

    public function send(Conversation $conversation, User $user, string $body, ?UploadedFile $file = null, string $kind = 'text', ?int $durationMs = null): ChatMessage
    {
        $storedPath = null;
        if ($file !== null) {
            $storedPath = $file->store('chat/'.$conversation->organization_id, 'local');
            $mime = strtolower((string) ($file->getMimeType() ?: 'application/octet-stream'));
            $kind = match (true) {
                $kind === 'voice' => 'voice',
                str_starts_with($mime, 'image/') => 'image',
                str_starts_with($mime, 'audio/') => 'voice',
                default => 'file',
            };
        }

        $preview = trim($body) !== '' ? $body : match ($kind) {
            'image' => 'Photo',
            'voice' => 'Voice note',
            'file' => $file?->getClientOriginalName() ?: 'Attachment',
            default => '',
        };

        $message = DB::transaction(function () use ($conversation, $user, $body, $file, $kind, $storedPath, $preview, $durationMs) {
            $message = $conversation->messages()->create([
                'user_id' => $user->id,
                'body' => $body,
                'kind' => $kind,
            ]);

            if ($file !== null && $storedPath !== null) {
                $message->attachments()->create([
                    'filename' => $file->getClientOriginalName() ?: 'upload',
                    'content_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size' => $file->getSize() ?: 0,
                    'duration_ms' => $durationMs,
                    'disk' => 'local',
                    'path' => $storedPath,
                ]);
            }

            $conversation->forceFill([
                'last_message_preview' => Str::limit(preg_replace('/\s+/', ' ', $preview) ?? $preview, 140),
                'last_message_at' => $message->created_at,
            ])->save();

            $conversation->participants()
                ->where('user_id', $user->id)
                ->update(['last_read_at' => $message->created_at]);

            return $message;
        });

        $message->load(['user:id,name', 'attachments']);

        $participantIds = $conversation->participants()->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        ChatMessageSent::dispatch($message, $participantIds);
        SendChatPush::dispatch($message->id)->afterCommit();

        return $message;
    }

    public function pin(Conversation $conversation, User $user, bool $pinned): void
    {
        $conversation->participants()
            ->where('user_id', $user->id)
            ->update(['pinned_at' => $pinned ? now() : null]);
    }

    public function markRead(Conversation $conversation, User $user): ?string
    {
        $participant = $conversation->participants()->where('user_id', $user->id)->first();

        if ($participant === null || $this->caughtUp($participant->last_read_at, $conversation->last_message_at)) {
            return null;
        }

        $at = now();
        $participant->forceFill([
            'last_read_at' => $at,
            'last_delivered_at' => $at,
        ])->save();

        return $at->toIso8601String();
    }

    public function markDelivered(Conversation $conversation, User $user): ?string
    {
        $participant = $conversation->participants()->where('user_id', $user->id)->first();

        if ($participant === null || $this->caughtUp($participant->last_delivered_at, $conversation->last_message_at)) {
            return null;
        }

        $at = now();
        $participant->forceFill(['last_delivered_at' => $at])->save();

        return $at->toIso8601String();
    }

    private function caughtUp(?CarbonInterface $stamp, ?CarbonInterface $latest): bool
    {
        return $stamp !== null && ($latest === null || $stamp->greaterThanOrEqualTo($latest));
    }

    /**
     * @param  list<int>  $userIds
     */
    public function addMembers(Conversation $conversation, Organization $organization, User $actor, array $userIds): Conversation
    {
        if ($conversation->type !== ConversationType::Group) {
            throw ValidationException::withMessages([
                'user_ids' => 'People can only be added to a group.',
            ]);
        }

        $memberIds = $this->memberIds($organization, $actor, $userIds);

        foreach ($memberIds as $memberId) {
            $conversation->participants()->firstOrCreate(
                ['user_id' => $memberId],
                ['role' => ConversationParticipant::RoleMember],
            );
        }

        return $conversation->load(['participants.user:id,name']);
    }

    /**
     * @return Collection<int, ChatMessage>
     */
    public function messages(Conversation $conversation, ?int $before = null): Collection
    {
        return ChatMessage::query()
            ->with(['user:id,name', 'attachments'])
            ->where('conversation_id', $conversation->id)
            ->when($before, fn ($query) => $query->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->reverse()
            ->values();
    }

    private function openDirect(Organization $organization, User $user, int $otherUserId): Conversation
    {
        $key = collect([$user->id, $otherUserId])->sort()->implode(':');

        try {
            return DB::transaction(function () use ($organization, $user, $otherUserId, $key) {
                $existing = Conversation::query()
                    ->where('organization_id', $organization->id)
                    ->where('direct_key', $key)
                    ->first();

                if ($existing) {
                    return $existing->load(['participants.user:id,name']);
                }

                $conversation = Conversation::query()->create([
                    'organization_id' => $organization->id,
                    'type' => ConversationType::Direct,
                    'direct_key' => $key,
                    'created_by' => $user->id,
                    'last_message_at' => now(),
                ]);

                $this->attachParticipants($conversation, $user, [$otherUserId]);

                return $conversation->load(['participants.user:id,name']);
            });
        } catch (UniqueConstraintViolationException) {
            return Conversation::query()
                ->where('organization_id', $organization->id)
                ->where('direct_key', $key)
                ->with(['participants.user:id,name'])
                ->firstOrFail();
        }
    }

    /**
     * @param  list<int>  $memberIds
     */
    private function openGroup(Organization $organization, User $user, ?string $name, array $memberIds): Conversation
    {
        $conversation = Conversation::query()->create([
            'organization_id' => $organization->id,
            'type' => ConversationType::Group,
            'name' => trim((string) $name),
            'created_by' => $user->id,
            'last_message_at' => now(),
        ]);

        $this->attachParticipants($conversation, $user, $memberIds);

        return $conversation->load(['participants.user:id,name']);
    }

    /**
     * @param  list<int>  $memberIds
     */
    private function attachParticipants(Conversation $conversation, User $owner, array $memberIds): void
    {
        $conversation->participants()->create([
            'user_id' => $owner->id,
            'role' => ConversationParticipant::RoleAdmin,
            'last_read_at' => now(),
        ]);

        foreach ($memberIds as $memberId) {
            $conversation->participants()->create([
                'user_id' => $memberId,
                'role' => ConversationParticipant::RoleMember,
            ]);
        }
    }

    /**
     * @param  list<int>  $userIds
     * @return list<int>
     */
    private function memberIds(Organization $organization, User $actor, array $userIds): array
    {
        $ids = collect($userIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn (int $id) => $id === $actor->id)
            ->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages([
                'user_ids' => 'Choose someone else in this workspace.',
            ]);
        }

        $allowed = $organization->users()->whereKey($ids)->pluck('users.id');

        if ($allowed->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'user_ids' => 'One or more people are not in this workspace.',
            ]);
        }

        return $ids->all();
    }
}
