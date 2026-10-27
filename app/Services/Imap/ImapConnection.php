<?php

namespace App\Services\Imap;

use RuntimeException;

/**
 * Wrapper around native PHP IMAP functions to enable testing.
 *
 * In tests, this class can be bound to a fake implementation.
 */
class ImapConnection
{
    /** @var resource|false */
    protected $stream = false;

    protected string $mailboxPath = '';

    public function __construct(
        protected string $host,
        protected int $port = 993,
        protected string $encryption = 'ssl',
    ) {}

    /**
     * Build the IMAP mailbox path string for a given folder.
     */
    public function mailboxPath(string $folder = 'INBOX'): string
    {
        $flags = '/imap';
        if ($this->encryption === 'ssl') {
            $flags .= '/ssl';
        } elseif ($this->encryption === 'tls') {
            $flags .= '/tls';
        } else {
            $flags .= '/notls';
        }
        $flags .= '/novalidate-cert';

        return '{'.$this->host.':'.$this->port.$flags.'}'.$folder;
    }

    /**
     * Open an IMAP connection.
     *
     * @throws RuntimeException
     */
    public function open(string $username, string $password, string $folder = 'INBOX'): void
    {
        if (! function_exists('imap_open')) {
            throw new RuntimeException('PHP IMAP extension is required.');
        }

        $this->mailboxPath = $this->mailboxPath($folder);
        $this->stream = @imap_open($this->mailboxPath, $username, $password);

        if ($this->stream === false) {
            throw new RuntimeException(imap_last_error() ?: 'imap_open failed');
        }
    }

    /**
     * Close the current IMAP connection.
     */
    public function close(): void
    {
        if ($this->stream !== false) {
            @imap_close($this->stream);
            $this->stream = false;
        }
    }

    /**
     * Check if the connection is open.
     */
    public function isOpen(): bool
    {
        return $this->stream !== false;
    }

    /**
     * Get the UIDVALIDITY of the mailbox.
     */
    public function uidValidity(): ?string
    {
        if ($this->stream === false) {
            return null;
        }

        $status = @imap_status($this->stream, $this->mailboxPath, SA_UIDVALIDITY);
        if ($status === false || ! isset($status->uidvalidity)) {
            return null;
        }

        return (string) $status->uidvalidity;
    }

    /**
     * Get the number of messages in the mailbox.
     */
    public function messageCount(): int
    {
        if ($this->stream === false) {
            return 0;
        }

        return imap_num_msg($this->stream);
    }

    /**
     * Get the UID of a message by its sequence number.
     */
    public function messageUid(int $msgno): int
    {
        if ($this->stream === false) {
            return 0;
        }

        return (int) imap_uid($this->stream, $msgno);
    }

    /**
     * Fetch header and body for a message by UID.
     */
    public function fetchRaw(int $uid): string
    {
        if ($this->stream === false) {
            return '';
        }

        return imap_fetchheader($this->stream, $uid, FT_UID).imap_body($this->stream, $uid, FT_UID);
    }

    /**
     * Fetch overview for a message by UID.
     *
     * @return object|null
     */
    public function fetchOverview(int $uid)
    {
        if ($this->stream === false) {
            return null;
        }

        $overview = imap_fetch_overview($this->stream, (string) $uid, FT_UID);

        return $overview[0] ?? null;
    }

    /**
     * Get the message number for a UID.
     */
    public function messageNumber(int $uid): int
    {
        if ($this->stream === false) {
            return 0;
        }

        return imap_msgno($this->stream, $uid);
    }

    /**
     * Get header info for a message by message number.
     *
     * @return object|false
     */
    public function headerInfo(int $msgno)
    {
        if ($this->stream === false) {
            return false;
        }

        return imap_headerinfo($this->stream, $msgno);
    }

    /**
     * Append a message to a folder.
     *
     * @param  string  $folder  The folder to append to (e.g., 'INBOX', 'Sent')
     * @param  string  $message  The full RFC822 message
     * @param  string|null  $flags  IMAP flags (e.g., '\Seen')
     * @param  string|null  $internalDate  The internal date in dd-Mon-yyyy HH:mm:ss +ZZZZ format
     * @return bool True on success
     */
    public function append(string $folder, string $message, ?string $flags = null, ?string $internalDate = null): bool
    {
        if ($this->stream === false) {
            return false;
        }

        $mailbox = $this->mailboxPath($folder);

        return @imap_append($this->stream, $mailbox, $message, $flags, $internalDate);
    }

    /**
     * Create a new ImapConnection from the Stalwart config.
     */
    public static function fromConfig(): self
    {
        $cfg = config('maildesk.stalwart.clients.imap', []);

        return new self(
            host: (string) ($cfg['host'] ?? '127.0.0.1'),
            port: (int) ($cfg['port'] ?? 993),
            encryption: strtolower((string) ($cfg['encryption'] ?? 'ssl')),
        );
    }
}
