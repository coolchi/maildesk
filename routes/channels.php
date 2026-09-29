<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('organizations.{organizationId}.inbox', function ($user, int $organizationId) {
    if ($user->isPlatformAdmin()) {
        return true;
    }

    return $user->organizations()->whereKey($organizationId)->exists();
});

Broadcast::channel('conversations.{conversationId}', function ($user, int $conversationId) {
    return $user->organizations()
        ->whereHas('conversations', function ($query) use ($conversationId, $user) {
            $query->whereKey($conversationId)
                ->whereHas('participants', fn ($participants) => $participants->where('user_id', $user->id));
        })
        ->exists();
});

Broadcast::channel('users.{userId}.chat', function ($user, int $userId) {
    return (int) $user->id === $userId;
});
