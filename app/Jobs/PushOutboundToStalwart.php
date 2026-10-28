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
 * Pushes an outbound message to the user's Stalwart IMAP Sent folder.
 *
 * This job is dispatched after an outbound message is sent from the MailDesk
 * web app. It uses IMAP APPEND to add the message to the Sent folder with
 * the \Seen flag so that IMAP clients show it as already read.
 */
class PushOutboundToStalwart implements ShouldQueue
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

        if ($message->direction !== 'outbound') {
            return;
        }

        if ($message->stalwart_pushed_at !== null) {
            return;
        }

        $mailbox = $message->mailbox;
        if (! $this->canPush($mailbox)) {
            Log::info('PushOutboundToStalwart: skipping - mailbox has no IMAP credentials', [
                'message_id' => $this->messageId,
                'mailbox' => $mailbox?->email,
            ]);

            return;
        }

        $rfc822 = $this->buildRfc822($message);
        if ($rfc822 === null) {
            Log::warning('PushOutboundToStalwart: could not build RFC822 message', [
                'message_id' => $this->messageId,
            ]);

            return;
        }

        $internalDate = $this->formatInternalDate($message->sent_at ?? $message->created_at);

        try {
            $imap->open($mailbox->email, $mailbox->imap_password, 'INBOX');

            $sentFolder = $this->findOrCreateSentFolder($imap);
            if ($sentFolder === null) {
                Log::info('PushOutboundToStalwart: no Sent folder available', [
                    'message_id' => $this->messageId,
                    'mailbox' => $mailbox->email,
                ]);
                $imap->close();

                return;
            }

            $appended = $imap->append($sentFolder, $rfc822, '\\Seen', $internalDate);
            $imap->close();

            if (! $appended) {
                $message->increment('stalwart_push_attempts');
                Log::warning('PushOutboundToStalwart: IMAP APPEND to Sent folder returned false', [
                    'message_id' => $this->messageId,
                    'mailbox' => $mailbox->email,
                    'folder' => $sentFolder,
                    'attempts' => $message->stalwart_push_attempts,
                ]);

                return;
            }

            $message->forceFill(['stalwart_pushed_at' => now()])->save();

            Log::info('PushOutboundToStalwart: message appended to Stalwart Sent folder', [
                'message_id' => $this->messageId,
                'mailbox' => $mailbox->email,
                'folder' => $sentFolder,
            ]);
        } catch (Throwable $e) {
            $imap->close();

            $message->increment('stalwart_push_attempts');
            Log::warning('PushOutboundToStalwart: IMAP operation failed', [
                'message_id' => $this->messageId,
                'mailbox' => $mailbox->email,
                'error' => $e->getMessage(),
                'attempts' => $message->stalwart_push_attempts,
            ]);
        }
    }

    /**
     * Find the Sent folder by common names or create one if it doesn't exist.
     */
    protected function findOrCreateSentFolder(ImapConnection $imap): ?string
    {
        $sentFolder = $imap->findSentFolder();
        if ($sentFolder !== null) {
            Log::debug('PushOutboundToStalwart: found Sent folder', [
                'message_id' => $this->messageId,
                'folder' => $sentFolder,
            ]);

            return $sentFolder;
        }

        Log::debug('PushOutboundToStalwart: no Sent folder found, attempting to create', [
            'message_id' => $this->messageId,
            'available_folders' => array_keys($imap->listFolders()),
        ]);

        if ($imap->createFolder('Sent')) {
            $imap->subscribeFolder('Sent');

            return 'Sent';
        }

        return null;
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

        $bcc = $this->parseAddresses($message->bcc);
        if ($bcc !== []) {
            $email->bcc(...$bcc);
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

        if ($message->sent_at) {
            $headers->addDateHeader('Date', $message->sent_at);
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

    protected function formatInternalDate(?Carbon $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return $date->format('d-M-Y H:i:s O');
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

    public function failed(?Throwable $exception): void
    {
        Log::error('PushOutboundToStalwart job failed permanently', [
            'message_id' => $this->messageId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
