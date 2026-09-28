<?php

namespace App\Support;

use App\Models\Organization;

class InboxSyncState
{
    /**
     * @return array{unread: int, cursor: string}
     */
    public static function for(Organization $organization, ?int $mailboxId = null): array
    {
        $base = fn () => $organization->threads()
            ->where('is_archived', false)
            ->where('is_spam', false)
            ->where('is_trashed', false)
            ->when($mailboxId !== null, fn ($query) => $query->where('mailbox_id', $mailboxId));

        $unread = $base()->where('is_read', false)->count();

        $latest = $base()
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->first(['id', 'last_message_at', 'message_count']);

        $cursor = $latest
            ? sprintf(
                '%d:%d:%d',
                $latest->last_message_at?->getTimestamp() ?? 0,
                $latest->id,
                (int) $latest->message_count,
            )
            : '0:0:0';

        return [
            'unread' => $unread,
            'cursor' => $cursor,
        ];
    }
}
