<?php

namespace App\Mail\DTO;

class ProviderSendResult
{
    public function __construct(
        public bool $success,
        public ?string $providerMessageId = null,
        public ?string $error = null,
        public array $raw = [],
    ) {}
}
