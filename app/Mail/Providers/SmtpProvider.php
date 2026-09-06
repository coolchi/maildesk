<?php

namespace App\Mail\Providers;

use App\Mail\Contracts\MailProvider;
use App\Mail\DTO\OutboundEmail;
use App\Mail\DTO\ProviderSendResult;
use Illuminate\Mail\Message as MailMessage;
use Illuminate\Support\Facades\Mail;
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
