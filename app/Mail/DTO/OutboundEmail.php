<?php

namespace App\Mail\DTO;

class OutboundEmail
{
    /**
     * @param  array<int, string|array{email: string, name?: string}>  $to
     * @param  array<int, string>|null  $cc
     * @param  array<int, string>|null  $bcc
     * @param  array<int, string>|null  $replyTo
     * @param  array<int, string>|null  $tags
     * @param  array<string, mixed>|null  $headers
     * @param  array<int, array{filename: string, content_type: string, path: string, disk?: string}>|null  $attachments
     */
    public function __construct(
        public string $fromEmail,
        public ?string $fromName,
        public array $to,
        public string $subject,
        public ?string $html = null,
        public ?string $text = null,
        public ?array $cc = null,
        public ?array $bcc = null,
        public ?array $replyTo = null,
        public ?array $tags = null,
        public ?array $headers = null,
        public ?array $attachments = null,
    ) {}
}
