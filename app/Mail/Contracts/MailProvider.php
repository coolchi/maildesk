<?php

namespace App\Mail\Contracts;

use App\Mail\DTO\OutboundEmail;
use App\Mail\DTO\ProviderSendResult;

interface MailProvider
{
    public function name(): string;

    public function send(OutboundEmail $email): ProviderSendResult;
}
