<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Builds short inbox list previews from email bodies.
 * Skips forwarded/original-message wrappers so the list shows the real first line.
 */
class EmailSnippet
{
    private const LIMIT = 180;

    /**
     * @var list<string>
     */
    private const HEADER_FIELDS = [
        'From',
        'To',
        'Cc',
        'Bcc',
        'Date',
        'Subject',
        'Reply-To',
        'Sent',
    ];

    public static function from(?string $text, ?string $html = null, int $limit = self::LIMIT): string
    {
        $raw = trim((string) $text);
        if ($raw === '') {
            $raw = self::textFromHtml((string) $html);
        }

        if ($raw === '') {
            return '';
        }

        return self::collapse(self::preferReadableBody($raw), $limit);
    }

    /**
     * Clean a stored snippet for display (fixes older forwarded-only previews).
     */
    public static function display(?string $snippet, int $limit = self::LIMIT): string
    {
        $raw = trim((string) $snippet);
        if ($raw === '') {
            return '';
        }

        return self::collapse(self::preferReadableBody($raw), $limit);
    }

    private static function textFromHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $withBreaks = preg_replace('/<\s*br\s*\/?\s*>/iu', "\n", $html) ?? $html;
        $withBreaks = preg_replace('/<\s*\/\s*(p|div|tr|h[1-6]|li)\s*>/iu', "\n", $withBreaks) ?? $withBreaks;

        return trim(html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function preferReadableBody(string $text): string
    {
        $marker = '/(?:\A|\R)\s*(?:-{2,}\s*Forwarded message\s*-{2,}|Begin forwarded message:|-{2,}\s*Original Message\s*-{2,})/iu';

        if (! preg_match($marker, $text, $match, PREG_OFFSET_CAPTURE)) {
            return $text;
        }

        $before = trim(substr($text, 0, $match[0][1]));
        if ($before !== '') {
            return $before;
        }

        $after = ltrim(substr($text, $match[0][1] + strlen($match[0][0])));

        return self::stripForwardHeaders($after);
    }

    private static function stripForwardHeaders(string $text): string
    {
        if (! preg_match('/\R/u', $text)) {
            return self::stripCollapsedHeaders($text);
        }

        $fields = implode('|', self::HEADER_FIELDS);
        $lines = preg_split('/\R/u', $text) ?: [];
        $index = 0;
        $count = count($lines);
        $sawHeader = false;

        while ($index < $count) {
            $line = trim($lines[$index]);

            if ($line === '') {
                if ($sawHeader) {
                    $index++;
                    break;
                }
                $index++;

                continue;
            }

            if (preg_match('/^(?:'.$fields.')\s*:/iu', $line) === 1) {
                $sawHeader = true;
                $index++;

                continue;
            }

            break;
        }

        return trim(implode("\n", array_slice($lines, $index)));
    }

    private static function stripCollapsedHeaders(string $text): string
    {
        $fields = implode('|', self::HEADER_FIELDS);
        $collapsed = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        while (preg_match('/^(?:'.$fields.')\s*:\s*/iu', $collapsed, $header) === 1) {
            $collapsed = ltrim(substr($collapsed, strlen($header[0])));

            if (preg_match('/^(.*?)(?=\s+(?:'.$fields.')\s*:)/iu', $collapsed, $value) === 1) {
                $collapsed = ltrim(substr($collapsed, strlen($value[1])));

                continue;
            }

            // Last header field: keep only the address/name, leave the rest as the body.
            if (preg_match('/^(?:[^@]*<[^>]+>|\S+@\S+|\S+(?:\s+\S+){0,5})\s+(.*)$/u', $collapsed, $parts) === 1
                && trim($parts[1]) !== '') {
                return trim($parts[1]);
            }

            return '';
        }

        return $collapsed;
    }

    private static function collapse(string $text, int $limit): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), $limit);
    }
}
