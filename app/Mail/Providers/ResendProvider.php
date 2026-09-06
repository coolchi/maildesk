<?php

namespace App\Mail\Providers;

use App\Mail\Contracts\MailProvider;
use App\Mail\DTO\OutboundEmail;
use App\Mail\DTO\ProviderSendResult;
use Illuminate\Support\Facades\Storage;
use Resend;
use Resend\Client;
use Throwable;

class ResendProvider implements MailProvider
{
    public function __construct(
        protected ?string $apiKey = null,
    ) {}

    public function name(): string
    {
        return 'resend';
    }

    public function send(OutboundEmail $email): ProviderSendResult
    {
        $apiKey = $this->apiKey ?: config('maildesk.providers.resend.api_key');

        if (blank($apiKey)) {
            return new ProviderSendResult(
                success: false,
                error: 'Resend API key is not configured. Set RESEND_API_KEY in your .env.',
            );
        }

        try {
            /** @var Client $client */
            $client = Resend::client($apiKey);

            $payload = [
                'from' => $email->fromName
                    ? "{$email->fromName} <{$email->fromEmail}>"
                    : $email->fromEmail,
                'to' => $this->normalizeAddresses($email->to),
                'subject' => $email->subject,
            ];

            if ($email->html) {
                $payload['html'] = $email->html;
            }

            if ($email->text) {
                $payload['text'] = $email->text;
            }

            if ($email->cc) {
                $payload['cc'] = $this->normalizeAddresses($email->cc);
            }

            if ($email->bcc) {
                $payload['bcc'] = $this->normalizeAddresses($email->bcc);
            }

            if ($email->replyTo) {
                $payload['reply_to'] = $this->normalizeAddresses($email->replyTo);
            }

            if ($email->tags) {
                $payload['tags'] = array_map(
                    fn (string $tag) => ['name' => $tag, 'value' => 'true'],
                    $email->tags,
                );
            }

            if ($email->headers) {
                $payload['headers'] = $email->headers;
            }

            if ($email->attachments) {
                $payload['attachments'] = array_values(array_map(function (array $attachment) {
                    $disk = $attachment['disk'] ?? 'local';
                    $contents = Storage::disk($disk)->get($attachment['path']);

                    return [
                        'filename' => $attachment['filename'],
                        'content' => base64_encode($contents),
                        'content_type' => $attachment['content_type'] ?? 'application/octet-stream',
                    ];
                }, $email->attachments));
            }

            $response = $client->emails->send($payload);
            $data = is_array($response) ? $response : (array) $response;
            $id = $data['id'] ?? (method_exists($response, 'toArray') ? ($response->toArray()['id'] ?? null) : null);

            return new ProviderSendResult(
                success: true,
                providerMessageId: $id,
                raw: $data,
            );
        } catch (Throwable $e) {
            return new ProviderSendResult(
                success: false,
                error: $e->getMessage(),
            );
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
                $email = $address['email'] ?? '';
                $name = $address['name'] ?? null;

                return $name ? "{$name} <{$email}>" : $email;
            }

            return (string) $address;
        }, $addresses));
    }
}
