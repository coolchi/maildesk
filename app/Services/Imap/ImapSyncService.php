<?php

namespace App\Services\Imap;

use App\Mail\Inbound\GenericInboundDriver;
use App\Models\Mailbox;
use App\Models\Thread;
use App\Services\InboundEmailService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls new IMAP messages for provisioned mailboxes into MailDesk threads.
 */
class ImapSyncService
{
    public function __construct(
        protected InboundEmailService $inbound,
        protected StalwartClient $client,
        protected ImapConnection $imap,
    ) {}

    /**
     * @return array{synced: int, mailboxes: int, errors: int}
     */
    public function syncAll(): array
    {
        if (! $this->client->enabled()) {
            return ['synced' => 0, 'mailboxes' => 0, 'errors' => 0];
        }

        $synced = 0;
        $errors = 0;
        $mailboxes = Mailbox::query()
            ->where('imap_enabled', true)
            ->whereNotNull('imap_password')
            ->where('status', 'active')
            ->get();

        foreach ($mailboxes as $mailbox) {
            try {
                $synced += $this->syncMailbox($mailbox);
            } catch (Throwable $e) {
                $errors++;
                Log::warning('IMAP sync failed', [
                    'mailbox' => $mailbox->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['synced' => $synced, 'mailboxes' => $mailboxes->count(), 'errors' => $errors];
    }

    public function syncMailbox(Mailbox $mailbox): int
    {
        $this->imap->open($mailbox->email, (string) $mailbox->imap_password, 'INBOX');

        try {
            $uidValidity = (string) ($this->imap->uidValidity() ?? '');
            $lastUid = (int) ($mailbox->imap_last_uid ?? 0);

            if ($uidValidity !== '' && $mailbox->imap_uidvalidity && $uidValidity !== (string) $mailbox->imap_uidvalidity) {
                Log::info('IMAP UIDVALIDITY changed, resetting sync', [
                    'mailbox' => $mailbox->email,
                    'old_uidvalidity' => $mailbox->imap_uidvalidity,
                    'new_uidvalidity' => $uidValidity,
                ]);
                $lastUid = 0;
            }

            $uids = [];
            $n = $this->imap->messageCount();
            for ($i = 1; $i <= $n; $i++) {
                $uid = $this->imap->messageUid($i);
                if ($uid > $lastUid) {
                    $uids[] = $uid;
                }
            }

            $count = 0;
            $maxUid = $lastUid;

            foreach ($uids as $uid) {
                $uid = (int) $uid;
                if ($uid <= $lastUid) {
                    continue;
                }

                $raw = $this->imap->fetchRaw($uid);

                if ($this->isMailDeskMessage($raw)) {
                    $maxUid = max($maxUid, $uid);

                    continue;
                }

                $ov = $this->imap->fetchOverview($uid);

                $parsed = $this->parseRaw($raw, $mailbox->email, $ov, $uid);
                if ($parsed === null) {
                    $maxUid = max($maxUid, $uid);

                    continue;
                }

                $inbound = GenericInboundDriver::fromArray('stalwart-imap', $parsed, 'imap-'.$mailbox->id.'-'.$uid);
                if ($inbound === null) {
                    $maxUid = max($maxUid, $uid);

                    continue;
                }

                $message = $this->inbound->receive($inbound);
                if ($message && $message->thread_id) {
                    $this->applyFolderFlags($ov, $message->thread);
                    $count++;
                }

                $maxUid = max($maxUid, $uid);
            }

            $mailbox->forceFill([
                'imap_last_uid' => $maxUid > 0 ? $maxUid : $mailbox->imap_last_uid,
                'imap_uidvalidity' => $uidValidity !== '' ? $uidValidity : $mailbox->imap_uidvalidity,
            ])->save();

            return $count;
        } finally {
            $this->imap->close();
        }
    }

    /**
     * @param  object|null  $overview
     * @return array<string, mixed>|null
     */
    protected function parseRaw(string $raw, string $mailboxEmail, $overview, int $uid): ?array
    {
        $from = $overview->from ?? null;
        $subject = isset($overview->subject) ? $this->decodeMime((string) $overview->subject) : '';
        $messageId = isset($overview->message_id) ? (string) $overview->message_id : null;

        if (! filled($from)) {
            if (preg_match('/^From:\s*(.+)$/mi', $raw, $m)) {
                $from = trim($m[1]);
            }
        }
        if (! filled($from)) {
            return null;
        }

        $text = null;
        $html = null;
        // Body is often after first blank line in concatenated header+body.
        $parts = preg_split("/\r\n\r\n|\n\n/", $raw, 2);
        $body = $parts[1] ?? '';
        if (stripos($raw, 'Content-Type: text/html') !== false) {
            $html = $body;
        } else {
            $text = $body !== '' ? $body : '(empty)';
        }

        $inReplyTo = null;
        if (preg_match('/^In-Reply-To:\s*(.+)$/mi', $raw, $m)) {
            $inReplyTo = trim($m[1]);
        }
        $references = null;
        if (preg_match('/^References:\s*(.+)$/mi', $raw, $m)) {
            $references = trim($m[1]);
        }

        return [
            'from' => $from,
            'to' => [$mailboxEmail],
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'references' => $references,
            'envelope_to' => [$mailboxEmail],
            'id' => 'uid-'.$uid,
        ];
    }

    protected function decodeMime(string $value): string
    {
        $decoded = @imap_mime_header_decode($value);
        if (! is_array($decoded)) {
            return $value;
        }

        $out = '';
        foreach ($decoded as $part) {
            $out .= $part->text ?? '';
        }

        return $out !== '' ? $out : $value;
    }

    /**
     * Check if the raw message was pushed by MailDesk (contains our marker header).
     */
    protected function isMailDeskMessage(string $raw): bool
    {
        return (bool) preg_match('/^X-MailDesk-Message-Id:\s*.+$/mi', $raw);
    }

    /**
     * Mirror IMAP \Seen onto the MailDesk thread from FETCH FLAGS, never by reading the body.
     *
     * @param  object|null  $overview
     */
    protected function applyFolderFlags($overview, ?Thread $thread): void
    {
        if (! $thread || ! is_object($overview)) {
            return;
        }

        $seen = ! empty($overview->seen);
        if ($seen && ! $thread->is_read) {
            $thread->forceFill(['is_read' => true])->save();
        }
    }
}
