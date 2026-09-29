<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Support\Collection;

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $this->participants($conversation)
            ->contains(fn (ConversationParticipant $participant) => $participant->user_id === $user->id);
    }

    public function manage(User $user, Conversation $conversation): bool
    {
        return $this->participants($conversation)
            ->contains(fn (ConversationParticipant $participant) => $participant->user_id === $user->id
                && $participant->role === ConversationParticipant::RoleAdmin);
    }

    /**
     * @return Collection<int, ConversationParticipant>
     */
    private function participants(Conversation $conversation)
    {
        if ($conversation->relationLoaded('participants')) {
            return $conversation->participants;
        }

        return $conversation->participants()->get();
    }
}
