<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('organizations.{organizationId}.inbox', function ($user, int $organizationId) {
    if ($user->isPlatformAdmin()) {
        return true;
    }

    return $user->organizations()->whereKey($organizationId)->exists();
});
