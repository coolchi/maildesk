<?php

namespace App\Mail\Providers;

use App\Mail\Contracts\MailProvider;
use App\Mail\DTO\OutboundEmail;
use App\Mail\DTO\ProviderSendResult;
use Closure;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Sends through an SMTP server described by $config (host, port, username,
 * password, encryption: tls|ssl|none). The config is resolved by MailManager
 * (workspace SMTP settings → platform SMTP provider → MAILDESK_SMTP_* env);
 * Laravel's default mailer / MAIL_* settings are never used.
 *
 * A transport (or a factory receiving the config) can be injected for tests.
 */
class SmtpProvider implements MailProvider
{
    /** Headers that are set from OutboundEmail fields and must not be duplicated. */
    protected const RESERVED_HEADERS = [
        'from', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'sender', 'date',
        'return-path', 'mime-version', 'content-type', 'content-transfer-encoding',
    ];

    /** Headers holding one or more <message-id> values. */
    protected const ID_HEADERS = ['message-id', 'in-reply-to', 'references'];

    /**
     * @param  array<string, mixed>  $config
     * @param  TransportInterface|(Closure(array<string, mixed>): TransportInterface)|null  $transport
     */
    public function __construct(
        protected array $config = [],
        protected TransportInterface|Closure|null $transport = null,
    ) {}

    public function name(): string
    {
        return 'smtp';
    }

    public function send(OutboundEmail $email): ProviderSendResult
    {
        try {
            $message = $this->buildEmail($email);
            $messageId = $message->getHeaders()->get('Message-ID')->getBodyAsString();
            $providerMessageId = trim($messageId, "<> \t");

            $sent = $this->transport()->send($message, Envelope::create($message));

            return new ProviderSendResult(
                success: true,
                providerMessageId: $providerMessageId,
                raw: array_filter([
                    'message_id' => $providerMessageId,
                    // Queue id reported by the SMTP server, when it gave one.
                    'smtp_id' => $sent?->getMessageId() !== $providerMessageId ? $sent?->getMessageId() : null,
                ]),
            );
        } catch (Throwable $e) {
            return new ProviderSendResult(success: false, error: $e->getMessage());
        }
    }

    /**
     * Build the MIME message: addresses, bodies, attachments and every custom
     * header (List-Unsubscribe, In-Reply-To, References, X-MailDesk-Group…).
     */
    public function buildEmail(OutboundEmail $email): Email
    {
        $message = (new Email)
            ->from(new Address($email->fromEmail, (string) ($email->fromName ?? '')))
            ->subject($email->subject);

        $message->to(...$this->addresses($email->to));

        if ($cc = $this->addresses($email->cc ?? [])) {
            $message->cc(...$cc);
        }
        if ($bcc = $this->addresses($email->bcc ?? [])) {
            $message->bcc(...$bcc);
        }
        if ($replyTo = $this->addresses($email->replyTo ?? [])) {
            $message->replyTo(...$replyTo);
        }

        if ($email->text !== null && $email->text !== '') {
            $message->text($email->text);
        }
        if ($email->html !== null && $email->html !== '') {
            $message->html($email->html);
        } elseif ($email->text === null || $email->text === '') {
            $message->text('');
        }

        foreach ($email->attachments ?? [] as $attachment) {
            $contents = Storage::disk($attachment['disk'] ?? 'local')->get($attachment['path']);
            if ($contents === null) {
                throw new InvalidArgumentException("Attachment {$attachment['filename']} could not be read.");
            }
            $message->attach($contents, $attachment['filename'], $attachment['content_type'] ?? null);
        }

        $headers = $message->getHeaders();

        foreach ($email->headers ?? [] as $name => $value) {
            $name = trim((string) $name);
            if ($name === '' || $value === null || $value === '' || $value === []) {
                continue;
            }

            $lower = Str::lower($name);
            if (in_array($lower, self::RESERVED_HEADERS, true)) {
                continue;
            }

            $value = is_array($value) ? implode(' ', array_map('strval', $value)) : (string) $value;
            $headers->remove($name);

            $ids = in_array($lower, self::ID_HEADERS, true) ? $this->messageIds($value) : [];
            if ($ids !== []) {
                $headers->addIdHeader($name, $lower === 'message-id' ? $ids[0] : $ids);
            } else {
                $headers->addTextHeader($name, $value);
            }
        }

        if (! empty($email->tags) && ! $headers->has('X-MailDesk-Tags')) {
            $headers->addTextHeader('X-MailDesk-Tags', implode(', ', array_map('strval', $email->tags)));
        }

        if (! $headers->has('Message-ID')) {
            $domain = Str::after($email->fromEmail, '@') ?: 'maildesk.local';
            $headers->addIdHeader('Message-ID', Str::uuid().'@'.$domain);
        }

        return $message;
    }

    public function transport(): TransportInterface
    {
        if ($this->transport instanceof TransportInterface) {
            return $this->transport;
        }

        if ($this->transport instanceof Closure) {
            return $this->transport = ($this->transport)($this->config);
        }

        return $this->transport = static::makeTransport($this->config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function makeTransport(array $config): EsmtpTransport
    {
        $host = trim((string) ($config['host'] ?? ''));
        if ($host === '') {
            throw new InvalidArgumentException('SMTP host is not configured.');
        }

        $encryption = Str::lower((string) ($config['encryption'] ?? 'tls'));
        $port = (int) ($config['port'] ?? 0) ?: ($encryption === 'ssl' ? 465 : 587);

        // ssl = implicit TLS; tls = STARTTLS (required); none = plain text.
        $transport = new EsmtpTransport($host, $port, $encryption === 'ssl');

        if ($encryption === 'tls') {
            $transport->setRequireTls(true);
        } elseif ($encryption === 'none') {
            $transport->setAutoTls(false);
        }

        if (($username = (string) ($config['username'] ?? '')) !== '') {
            $transport->setUsername($username);
        }
        if (($password = (string) ($config['password'] ?? '')) !== '') {
            $transport->setPassword($password);
        }

        return $transport;
    }

    /**
     * @param  array<int, string|array{email: string, name?: string}>  $addresses
     * @return array<int, Address>
     */
    protected function addresses(array $addresses): array
    {
        $out = [];

        foreach ($addresses as $address) {
            if (is_array($address)) {
                $emailAddress = trim((string) ($address['email'] ?? ''));
                $name = (string) ($address['name'] ?? '');
            } else {
                $emailAddress = trim((string) $address);
                $name = '';
            }

            if ($emailAddress === '') {
                continue;
            }

            $out[] = $name !== '' ? new Address($emailAddress, $name) : Address::create($emailAddress);
        }

        return $out;
    }

    /**
     * @return array<int, string> ids without angle brackets
     */
    protected function messageIds(string $value): array
    {
        if (preg_match_all('/<([^<>\s]+@[^<>\s]+)>/', $value, $matches) && $matches[1] !== []) {
            return $matches[1];
        }

        $bare = trim($value);

        return preg_match('/^[^<>\s]+@[^<>\s]+$/', $bare) ? [$bare] : [];
    }
}
