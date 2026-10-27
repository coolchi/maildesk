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

            Log::warning('PushInboundToStalwart: IMAP APPEND failed', [
                'message_id' => $this->messageId,
                'mailbox' => $mailbox->email,
                'error' => $e->getMessage(),
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

        if (filled($message->in_reply_to)) {
            $inReplyTo = trim($message->in_reply_to, '<>');
            $headers->addIdHeader('In-Reply-To', $inReplyTo);
        }

        if (! empty($message->references)) {
            $refs = is_array($message->references) ? $message->references : [$message->references];
            $refIds = array_map(fn ($r) => trim((string) $r, '<>'), $refs);
            if ($refIds !== []) {
                $headers->addIdHeader('References', $refIds);
            }
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
