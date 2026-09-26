<?php

namespace App\Services;

use App\Models\Thread;
use Illuminate\Support\Facades\Storage;

/**
 * Permanently removes trashed conversations (messages + attachment files).
 */
class ThreadTrashService
{
    public function permanentlyDelete(Thread $thread): void
    {
        $thread->loadMissing(['messages.attachments']);

        foreach ($thread->messages as $message) {
            foreach ($message->attachments as $attachment) {
                Storage::disk($attachment->disk)->delete($attachment->path);
            }
            $message->attachments()->delete();
            $message->delete();
        }

        $thread->delete();
    }

    /**
     * @param  iterable<int, Thread>  $threads
     */
    public function permanentlyDeleteMany(iterable $threads): int
    {
        $count = 0;

        foreach ($threads as $thread) {
            $this->permanentlyDelete($thread);
            $count++;
        }

        return $count;
    }
}
