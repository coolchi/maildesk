<?php

namespace App\Jobs;

use App\Models\Attachment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Services\Imap\ImapConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Pushes an inbound message to the user's Stalwart IMAP mailbox.
 *
 * This job is dispatched after an inbound message is created and the mailbox
 * has IMAP enabled. It uses IMAP APPEND to add the message to the INBOX so
 * that IMAP clients (Outlook, Gmail app, Apple Mail) can see it.
 */
class PushInboundToStalwart implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public int $timeout = 60;

    public function __construct(
        public int $messageId,
    ) {}

    public function handle(ImapConnection $imap): void
    {
        if (! config('maildesk.stalwart.enabled')) {
            return;
        }

        $message = Message::query()
            ->with(['mailbox', 'attachments'])
            ->find($this->messageId);

        if ($message === null) {
            return;
        }

        if ($message->direction !== 'inbound') {
            return;
        }

        if ($message->stalwart_pushed_at !== null) {
            return;
        }

        if ($message->provider === 'stalwart-imap') {
            return;
        }

        $mailbox = $message->mailbox;
        if (! $this->canPush($mailbox)) {
            return;
        }

        $rfc822 = $this->buildRfc822($message);
        if ($rfc822 === null) {
            Log::warning('PushInboundToStalwart: could not build RFC822 message', [
                'message_id' => $this->messageId,
            ]);

            return;
        }

        $internalDate = $this->formatInternalDate($message->received_at ?? $message->created_at);

        try {
            $imap->open($mailbox->email, $mailbox->imap_password, 'INBOX');
            $appended = $imap->append('INBOX', $rfc822, null, $internalDate);
            $imap->close();

            if (! $appended) {
                throw new \RuntimeException('IMAP APPEND returned false');
            }

            $message->forceFill(['stalwart_pushed_at' => now()])->save();

            Log::info('PushInboundToStalwart: message appended to Stalwart', [
                'message_id' => $this->messageId,
                'mailbox' => $mailbox->email,
            ]);
        } catch (Throwable $e) {
            $imap->close();

            $message->increment('stalwart_push_attempts');
            Log::warning('PushInboundToStalwart: IMAP APPEND failed', [
                'message_id' => $this->messageId,
                'mailbox' => $mailbox->email,
                'error' => $e->getMessage(),
                'attempts' => $message->stalwart_push_attempts,
            ]);

            throw $e;
        }
    }

    protected function canPush(?Mailbox $mailbox): bool
    {
        if ($mailbox === null) {
            return false;
        }

        if (! $mailbox->imap_enabled) {
            return false;
        }

        if (blank($mailbox->imap_password)) {
            return false;
        }

        return true;
    }

    /**
     * Build a full RFC822 message from the stored Message model.
     */
    protected function buildRfc822(Message $message): ?string
    {
        $email = new Email;

        $email->from(new Address($message->from_email, $message->from_name ?? ''));

        $to = $this->parseAddresses($message->to);
        if ($to !== []) {
            $email->to(...$to);
        }

        $cc = $this->parseAddresses($message->cc);
        if ($cc !== []) {
            $email->cc(...$cc);
        }

        $email->subject($message->subject ?? '(no subject)');

        if (filled($message->text_body)) {
            $email->text($message->text_body);
        }
        if (filled($message->html_body)) {
            $email->html($message->html_body);
        }

        if (blank($message->text_body) && blank($message->html_body)) {
            $email->text('');
        }

        $headers = $email->getHeaders();

        $headers->addTextHeader('X-MailDesk-Message-Id', $message->uuid);

        if (filled($message->message_id_header)) {
            $headers->remove('Message-ID');
            $msgId = trim($message->message_id_header, '<>');
            $headers->addIdHeader('Message-ID', $msgId);
        }

        $inReplyToIds = $this->parseMessageIds($message->in_reply_to);
        if ($inReplyToIds !== []) {
            $headers->addIdHeader('In-Reply-To', count($inReplyToIds) === 1 ? $inReplyToIds[0] : $inReplyToIds);
        }

        $referenceIds = $this->parseMessageIds($message->references);
        if ($referenceIds !== []) {
            $headers->addIdHeader('References', $referenceIds);
        }

        if ($message->received_at) {
            $headers->addDateHeader('Date', $message->received_at);
        }

        $replyTo = $this->parseAddresses($message->reply_to);
        if ($replyTo !== []) {
            $email->replyTo(...$replyTo);
        }

        $this->attachFiles($email, $message);

        return $email->toString();
    }

    /**
     * @param  mixed  $addresses
     * @return Address[]
     */
    protected function parseAddresses($addresses): array
    {
        if (! is_array($addresses) || $addresses === []) {
            return [];
        }

        $result = [];
        foreach ($addresses as $address) {
            if (is_string($address) && filled($address)) {
                $result[] = Address::create($address);
            } elseif (is_array($address) && isset($address['email'])) {
                $result[] = new Address($address['email'], $address['name'] ?? '');
            }
        }

        return $result;
    }

    protected function attachFiles(Email $email, Message $message): void
    {
        foreach ($message->attachments as $attachment) {
            /** @var Attachment $attachment */
            $contents = Storage::disk($attachment->disk)->get($attachment->path);
            if ($contents === null) {
                continue;
            }

            $email->attach($contents, $attachment->filename, $attachment->content_type);
        }
    }

    /**
     * Parse message IDs from various stored formats.
     *
     * Handles: JSON strings, arrays, space-separated strings, single values,
     * and corrupted data where JSON was wrapped in angle brackets.
     * Returns bare IDs without angle brackets, skipping invalid ones.
     *
     * @param  mixed  $value
     * @return string[]
     */
    protected function parseMessageIds($value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $this->parseMessageIds($decoded);
            }

            if (preg_match_all('/<([^<>]+)>/', $value, $matches)) {
                $extracted = [];
                foreach ($matches[1] as $inner) {
                    $innerDecoded = json_decode($inner, true);
                    if (is_array($innerDecoded)) {
                        $extracted = array_merge($extracted, $this->parseMessageIds($innerDecoded));
                    } elseif (str_contains($inner, '@')) {
                        $extracted[] = trim($inner);
                    }
                }
                if ($extracted !== []) {
                    return array_values(array_unique($extracted));
                }
            }

            if (str_contains($value, ' ')) {
                $value = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY);
            } else {
                $value = [$value];
            }
        }

        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $id) {
            if (is_array($id)) {
                $result = array_merge($result, $this->parseMessageIds($id));

                continue;
            }

            if (! is_string($id) || $id === '') {
                continue;
            }

            $decoded = json_decode($id, true);
            if (is_array($decoded)) {
                $result = array_merge($result, $this->parseMessageIds($decoded));

                continue;
            }

            if (preg_match('/^<(\[.+\])>$/', $id, $outerMatch)) {
                $innerDecoded = json_decode($outerMatch[1], true);
                if (is_array($innerDecoded)) {
                    $result = array_merge($result, $this->parseMessageIds($innerDecoded));

                    continue;
                }
            }

            $bare = trim($id, '<> ');
            if ($bare === '' || ! str_contains($bare, '@')) {
                continue;
            }

            $result[] = $bare;
        }

        return array_values(array_unique($result));
    }

    /**
     * Format a Carbon date for IMAP internal date (dd-Mon-yyyy HH:mm:ss +ZZZZ).
     */
    protected function formatInternalDate(?Carbon $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return $date->format('d-M-Y H:i:s O');
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('PushInboundToStalwart job failed permanently', [
            'message_id' => $this->messageId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
