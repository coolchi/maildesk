<?php

namespace App\Services;

use App\Jobs\DispatchWebhook;
use App\Mail\DTO\InboundEmail;
use App\Models\Attachment;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Thread;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InboundEmailService
{
    /**
     * Store a received email in the right tenant's shared inbox.
     * Returns null when no organization owns any of the recipients.
     */
    public function receive(InboundEmail $email): ?Message
    {
        [$organization, $mailbox] = $this->resolveRecipient($email);

        if ($organization === null) {
            Log::info('Inbound email dropped: no matching mailbox or domain', [
                'recipients' => $email->recipients(),
                'from' => $email->fromEmail,
            ]);

            return null;
        }

        if ($existing = $this->findDuplicate($organization, $email)) {
            return $existing;
        }

        $message = DB::transaction(function () use ($organization, $mailbox, $email) {
            $thread = $this->findThread($organization, $mailbox, $email)
                ?? Thread::query()->create([
                    'organization_id' => $organization->id,
                    'mailbox_id' => $mailbox?->id,
                    'subject' => $email->subject !== '' ? $email->subject : '(no subject)',
                    'message_count' => 0,
                    'is_read' => false,
                ]);

            $message = Message::query()->create([
                'organization_id' => $organization->id,
                'thread_id' => $thread->id,
                'mailbox_id' => $mailbox?->id ?? $thread->mailbox_id,
                'direction' => 'inbound',
                'status' => 'received',
                'provider' => $email->provider,
                'provider_message_id' => $email->providerMessageId,
                'message_id_header' => $email->messageId,
                'in_reply_to' => $email->inReplyTo,
                'references' => $email->references ?: null,
                'from_email' => $email->fromEmail,
                'from_name' => $email->fromName,
                'to' => $email->to,
                'cc' => $email->cc ?: null,
                'reply_to' => $email->replyTo ?: null,
                'subject' => $email->subject,
                'text_body' => $email->text,
                'html_body' => $email->html,
                'headers' => $email->headers ?: null,
                'received_at' => now(),
            ]);

            $this->storeAttachments($message, $email);

            $thread->forceFill([
                'mailbox_id' => $thread->mailbox_id ?? $mailbox?->id,
                'snippet' => $this->snippet($email),
                'last_message_at' => now(),
                'message_count' => $thread->messages()->count(),
                'is_read' => false,
            ])->save();

            return $message;
        });

        DispatchWebhook::dispatch($organization->id, 'email.received', [
            'id' => $message->uuid,
            'thread_id' => $message->thread_id,
            'mailbox' => $mailbox?->email,
            'from' => $message->from_email,
            'to' => $message->to,
            'subject' => $message->subject,
            'status' => $message->status,
        ]);

        // Mail to a group address (staff@...) is copied to each member via the queue.
        app(GroupAddressService::class)->routeInbound($message, $email->recipients());

        $fresh = $message->fresh(['attachments', 'thread']);
        $fresh->wasRecentlyCreated = true;

        return $fresh;
    }

    /**
     * Exact mailbox match first; otherwise the organization owning the domain (catch-all).
     *
     * @return array{0: ?Organization, 1: ?Mailbox}
     */
    protected function resolveRecipient(InboundEmail $email): array
    {
        $recipients = $email->recipients();
        if ($recipients === []) {
            return [null, null];
        }

        $mailboxes = Mailbox::query()
            ->with('organization')
            ->whereIn(DB::raw('lower(email)'), $recipients)
            ->where('status', 'active')
            ->where('inbox', true)
            ->get()
            ->sortBy(fn (Mailbox $m) => array_search(Str::lower($m->email), $recipients, true));

        if ($mailbox = $mailboxes->first()) {
            return [$mailbox->organization, $mailbox];
        }

        $domains = array_values(array_unique(array_map(
            fn (string $address) => Str::after($address, '@'),
            $recipients,
        )));

        $domain = Domain::query()
            ->with('organization')
            ->whereIn(DB::raw('lower(name)'), $domains)
            ->first();

        return [$domain?->organization, null];
    }

    protected function findDuplicate(Organization $organization, InboundEmail $email): ?Message
    {
        if ($email->messageId === null && $email->providerMessageId === null) {
            return null;
        }

        return Message::query()
            ->where('organization_id', $organization->id)
            ->where('direction', 'inbound')
            ->where(function ($query) use ($email) {
                if ($email->messageId !== null) {
                    $query->orWhere('message_id_header', $email->messageId);
                }
                if ($email->providerMessageId !== null) {
                    $query->orWhere(fn ($q) => $q
                        ->where('provider', $email->provider)
                        ->where('provider_message_id', $email->providerMessageId));
                }
            })
            ->first();
    }

    /**
     * Thread by In-Reply-To / References; fall back to the same sender and
     * normalised subject in the same mailbox within the last 30 days.
     */
    protected function findThread(Organization $organization, ?Mailbox $mailbox, InboundEmail $email): ?Thread
    {
        $ids = $email->referencedMessageIds();

        if ($ids !== []) {
            $related = Message::query()
                ->where('organization_id', $organization->id)
                ->whereIn('message_id_header', $ids)
                ->get(['thread_id', 'message_id_header'])
                ->sortBy(fn (Message $m) => array_search($m->message_id_header, $ids, true))
                ->first();

            if ($related) {
                return Thread::query()->where('organization_id', $organization->id)->find($related->thread_id);
            }
        }

        $subject = $this->normalizeSubject($email->subject);
        $isReply = $subject !== Str::lower(trim($email->subject));

        // Subject fallback only for replies/forwards ("Re:", "Fwd:") or when reply headers exist but matched nothing.
        if ($subject === '' || (! $isReply && $ids === [])) {
            return null;
        }

        return Thread::query()
            ->where('organization_id', $organization->id)
            ->when($mailbox, fn ($q) => $q->where('mailbox_id', $mailbox->id))
            ->where('last_message_at', '>=', now()->subDays(30))
            ->whereHas('messages', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('from_email', $email->fromEmail)
                ->orWhereJsonContains('to', $email->fromEmail)))
            ->latest('last_message_at')
            ->get()
            ->first(fn (Thread $t) => $this->normalizeSubject($t->subject) === $subject);
    }

    protected function normalizeSubject(string $subject): string
    {
        $subject = trim($subject);
        while (preg_match('/^(re|fw|fwd|aw|sv)\s*(\[\d+\])?\s*:\s*/i', $subject)) {
            $subject = trim(preg_replace('/^(re|fw|fwd|aw|sv)\s*(\[\d+\])?\s*:\s*/i', '', $subject));
        }

        return Str::lower($subject);
    }

    protected function snippet(InboundEmail $email): string
    {
        $text = $email->text ?? strip_tags((string) $email->html);

        return Str::limit(trim(preg_replace('/\s+/', ' ', $text)), 180);
    }

    protected function storeAttachments(Message $message, InboundEmail $email): void
    {
        $disk = (string) config('maildesk.inbound.attachments_disk', 'local');

        foreach ($email->attachments as $attachment) {
            $filename = $attachment['filename'] !== '' ? $attachment['filename'] : 'attachment';
            $path = 'attachments/'.$message->organization_id.'/inbound/'.Str::uuid().'-'.$filename;

            Storage::disk($disk)->put($path, $attachment['content']);

            Attachment::query()->create([
                'message_id' => $message->id,
                'filename' => $filename,
                'content_type' => $attachment['content_type'],
                'size' => strlen($attachment['content']),
                'disk' => $disk,
                'path' => $path,
            ]);
        }
    }
}
