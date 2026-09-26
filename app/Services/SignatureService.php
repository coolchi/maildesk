<?php

namespace App\Services;

use App\Models\Mailbox;
use App\Models\Organization;
use App\Support\EmailHtmlSanitizer;
use Illuminate\Support\Str;

/**
 * Email signatures: one per organization, optionally overridden per mailbox.
 *
 * Stored in organizations.settings['signature'] as
 * {enabled, html, api, broadcasts} and in mailboxes.signature.
 */
class SignatureService
{
    public const MARKER = 'data-maildesk-signature';

    /**
     * @return array{enabled: bool, html: string, api: bool, broadcasts: bool}
     */
    public function settings(Organization $organization): array
    {
        $saved = (array) (($organization->settings ?? [])['signature'] ?? []);

        return [
            'enabled' => (bool) ($saved['enabled'] ?? false),
            'html' => (string) ($saved['html'] ?? ''),
            'api' => (bool) ($saved['api'] ?? false),
            'broadcasts' => (bool) ($saved['broadcasts'] ?? true),
        ];
    }

    /**
     * The cleaned signature HTML for a from address, or null when there is none.
     * A mailbox's own signature wins over the organization's.
     */
    public function resolve(Organization $organization, string $fromEmail): ?string
    {
        $settings = $this->settings($organization);
        if (! $settings['enabled']) {
            return null;
        }

        $mailbox = Mailbox::query()
            ->where('organization_id', $organization->id)
            ->whereRaw('lower(email) = ?', [Str::lower(trim($fromEmail))])
            ->first();

        $html = $this->isBlank($mailbox?->signature) ? $settings['html'] : (string) $mailbox->signature;

        if ($this->isBlank($html)) {
            return null;
        }

        return trim((string) EmailHtmlSanitizer::clean($html));
    }

    /**
     * Append the signature to an html/text pair.
     *
     * @return array{html: ?string, text: ?string}
     */
    public function apply(?string $html, ?string $text, ?string $signature): array
    {
        if ($signature === null || $signature === '') {
            return ['html' => $html, 'text' => $text];
        }

        // Skip if this body already carries a signature (e.g. a retried draft).
        if ($html !== null && str_contains($html, self::MARKER)) {
            return ['html' => $html, 'text' => $text];
        }

        $block = '<div '.self::MARKER.'="1" style="margin-top:16px;padding-top:12px;border-top:1px solid #e4e4e7;color:#52525b;font-size:13px">'
            .$signature.'</div>';

        $newHtml = match (true) {
            $html === null || trim($html) === '' => $block,
            (bool) preg_match('~</body>~i', $html) => preg_replace('~</body>~i', $block.'</body>', $html, 1),
            default => $html.$block,
        };

        $plain = $this->toText($signature);
        $newText = trim((string) $text) === '' ? "-- \n".$plain : rtrim((string) $text)."\n\n-- \n".$plain;

        return ['html' => $newHtml, 'text' => $newText];
    }

    public function toText(string $html): string
    {
        $html = preg_replace('~<br\s*/?>~i', "\n", $html);
        $html = preg_replace('~</(p|div|li|h[1-6]|tr)>~i', "\n", $html);
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5);
        $lines = array_map('trim', explode("\n", $text));

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    protected function isBlank(?string $html): bool
    {
        return $html === null || trim(strip_tags($html, '<img>')) === '';
    }
}
