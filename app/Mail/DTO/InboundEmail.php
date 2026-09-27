<?php

namespace App\Mail\DTO;

use Illuminate\Support\Str;

/**
 * Provider-agnostic representation of a received email.
 */
class InboundEmail
{
    /**
     * When set, the body and/or attachments need to be fetched asynchronously.
     */
    public ?string $deferredFetchEmailId = null;

    /**
     * @param  array<int, string>  $to
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $replyTo
     * @param  array<int, string>  $references
     * @param  array<string, string>  $headers
     * @param  array<int, array{filename: string, content_type: string, content: string}>  $attachments
     * @param  array<int, string>  $envelopeRecipients
     */
    public function __construct(
        public string $provider,
        public string $fromEmail,
        public ?string $fromName,
        public array $to,
        public string $subject = '',
        public ?string $text = null,
        public ?string $html = null,
        public array $cc = [],
        public array $replyTo = [],
        public ?string $messageId = null,
        public ?string $inReplyTo = null,
        public array $references = [],
        public array $headers = [],
        public array $attachments = [],
        public ?string $providerMessageId = null,
        public array $envelopeRecipients = [],
        public array $raw = [],
    ) {}

    /**
     * Every address this email was delivered to, lower-cased and unique.
     *
     * @return array<int, string>
     */
    public function recipients(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (string $email) => Str::lower(trim($email)),
            [...$this->envelopeRecipients, ...$this->to, ...$this->cc],
        ))));
    }

    /**
     * Message-IDs this email refers to, most specific first.
     *
     * @return array<int, string>
     */
    public function referencedMessageIds(): array
    {
        return array_values(array_unique(array_filter([
            $this->inReplyTo,
            ...array_reverse($this->references),
        ])));
    }
}
