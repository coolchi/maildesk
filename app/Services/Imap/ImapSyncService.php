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
        if (! function_exists('imap_open')) {
            throw new \RuntimeException('PHP IMAP extension is required for mail:sync-imap.');
        }

        $cfg = config('maildesk.stalwart.clients.imap', []);
        $host = (string) ($cfg['host'] ?? '127.0.0.1');
        $port = (int) ($cfg['port'] ?? 993);
        $enc = strtolower((string) ($cfg['encryption'] ?? 'ssl'));

        $flags = '/imap';
        if ($enc === 'ssl') {
            $flags .= '/ssl';
        } elseif ($enc === 'tls') {
            $flags .= '/tls';
        } else {
            $flags .= '/notls';
        }
        // Self-signed / local spike hosts.
        $flags .= '/novalidate-cert';

        $mailboxPath = '{'.$host.':'.$port.$flags.'}INBOX';
        $stream = @imap_open($mailboxPath, $mailbox->email, (string) $mailbox->imap_password);
        if ($stream === false) {
            throw new \RuntimeException(imap_last_error() ?: 'imap_open failed');
        }

        try {
            $status = @imap_status($stream, $mailboxPath, SA_UIDVALIDITY);
            $uidValidity = $status && isset($status->uidvalidity) ? (string) $status->uidvalidity : '';
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
            $n = imap_num_msg($stream);
            for ($i = 1; $i <= $n; $i++) {
                $uid = (int) imap_uid($stream, $i);
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

                $raw = imap_fetchheader($stream, $uid, FT_UID).imap_body($stream, $uid, FT_UID);
                $overview = imap_fetch_overview($stream, (string) $uid, FT_UID);
                $ov = $overview[0] ?? null;

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
                    $this->applyFolderFlags($stream, $uid, $message->thread);
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
            imap_close($stream);
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
     * @param  resource  $stream
     */
    protected function applyFolderFlags($stream, int $uid, ?Thread $thread): void
    {
        if (! $thread) {
            return;
        }

        $msgno = imap_msgno($stream, $uid);
        if ($msgno < 1) {
            return;
        }

        $headers = imap_headerinfo($stream, $msgno);
        $unseen = isset($headers->Unseen) && $headers->Unseen === 'U';
        // Keep MailDesk unread aligned with IMAP \Seen.
        if (! $unseen && ! $thread->is_read) {
            $thread->forceFill(['is_read' => true])->save();
        }
    }
}
