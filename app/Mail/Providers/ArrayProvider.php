<?php

namespace App\Mail\Providers;

use App\Mail\Contracts\MailProvider;
use App\Mail\DTO\OutboundEmail;
use App\Mail\DTO\ProviderSendResult;
use Illuminate\Support\Str;

class ArrayProvider implements MailProvider
{
    public function __construct(
        protected string $driver = 'array',
    ) {}

    public function name(): string
    {
        return $this->driver;
    }

    public function send(OutboundEmail $email): ProviderSendResult
    {
        return new ProviderSendResult(
            success: true,
            providerMessageId: $this->driver.'_'.Str::lower(Str::random(16)),
            raw: [
                'driver' => $this->driver,
                'from' => $email->fromEmail,
                'to' => $email->to,
                'subject' => $email->subject,
                'attachments' => count($email->attachments ?? []),
            ],
        );
    }
}
