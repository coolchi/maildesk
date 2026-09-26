<?php

namespace App\Mail\Providers;

use App\Mail\Contracts\MailProvider;
use App\Mail\DTO\OutboundEmail;
use App\Mail\DTO\ProviderSendResult;
use Illuminate\Mail\Message as MailMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Throwable;

class SmtpProvider implements MailProvider
{
    public function __construct(
        protected array $config = [],
    ) {}

    public function name(): string
    {
        return 'smtp';
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

    public function send(OutboundEmail $email): ProviderSendResult
    {
        try {
            Mail::mailer('smtp')->html($email->html ?: nl2br(e($email->text ?? '')), function (MailMessage $message) use ($email) {
                $message
                    ->from($email->fromEmail, $email->fromName)
                    ->subject($email->subject);

                foreach ($this->normalizeAddresses($email->to) as $to) {
                    $message->to($to);
                }

                foreach ($this->normalizeAddresses($email->cc ?? []) as $cc) {
                    $message->cc($cc);
                }

                foreach ($this->normalizeAddresses($email->bcc ?? []) as $bcc) {
                    $message->bcc($bcc);
                }

                foreach ($this->normalizeAddresses($email->replyTo ?? []) as $replyTo) {
                    $message->replyTo($replyTo);
                }

                foreach ($email->attachments ?? [] as $attachment) {
                    $disk = $attachment['disk'] ?? 'local';
                    $message->attachFromStorageDisk(
                        $disk,
                        $attachment['path'],
                        $attachment['filename'],
                        ['mime' => $attachment['content_type'] ?? null],
                    );
                }
            });

            return new ProviderSendResult(success: true, providerMessageId: null);
        } catch (Throwable $e) {
            return new ProviderSendResult(success: false, error: $e->getMessage());
        }
    }

    /**
     * @param  array<int, string|array{email: string, name?: string}>  $addresses
     * @return array<int, string>
     */
    protected function normalizeAddresses(array $addresses): array
    {
        return array_values(array_map(function ($address) {
            if (is_array($address)) {
                return $address['email'] ?? '';
            }

            return (string) $address;
        }, $addresses));
    }
}
