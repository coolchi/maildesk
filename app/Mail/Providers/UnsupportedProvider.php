<?php

namespace App\Mail\Providers;

use App\Mail\Contracts\MailProvider;
use App\Mail\DTO\OutboundEmail;
use App\Mail\DTO\ProviderSendResult;

class UnsupportedProvider implements MailProvider
{
    public function __construct(
        protected string $driver,
    ) {}

    public function name(): string
    {
        return $this->driver;
    }

    public function send(OutboundEmail $email): ProviderSendResult
    {
        return new ProviderSendResult(
            success: false,
            error: "Unsupported mail provider [{$this->driver}]. Configure a supported driver or enable MAILDESK_FAKE_SEND.",
            raw: ['driver' => $this->driver],
        );
    }
}
