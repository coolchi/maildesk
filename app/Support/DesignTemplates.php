<?php

namespace App\Support;

use App\Models\Organization;

/**
 * Built-in email layouts. They frame a message; they are not the message.
 * A send with no design goes out as the written HTML.
 */
class DesignTemplates
{
    /**
     * @return list<array{key: string, name: string, description: string, accent: string, background: string, fragment: string}>
     */
    public static function all(): array
    {
        return [
            self::aurora(),
            self::editorial(),
            self::signal(),
            self::linen(),
            self::midnight(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    public static function normalize(mixed $key): ?string
    {
        $key = is_string($key) ? trim($key) : '';

        return in_array($key, self::keys(), true) ? $key : null;
    }

    /**
     * @return array{key: string, name: string, description: string, accent: string, background: string, fragment: string}|null
     */
    public static function find(mixed $key): ?array
    {
        $key = self::normalize($key);
        if ($key === null) {
            return null;
        }

        foreach (self::all() as $design) {
            if ($design['key'] === $key) {
                return $design;
            }
        }

        return null;
    }

    public static function defaultKey(Organization $organization): ?string
    {
        return self::normalize($organization->settings['design_template'] ?? null);
    }

    public static function setDefault(Organization $organization, mixed $key): ?string
    {
        $normalized = self::normalize($key);
        $settings = $organization->settings ?? [];

        if ($normalized === null) {
            unset($settings['design_template']);
        } else {
            $settings['design_template'] = $normalized;
        }

        $organization->forceFill(['settings' => $settings])->save();

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    public static function colors(?Organization $organization): array
    {
        $stored = $organization?->settings['design_colors'] ?? [];
        if (! is_array($stored)) {
            return [];
        }

        $colors = [];
        foreach (self::all() as $design) {
            $value = $stored[$design['key']] ?? null;
            $colors[$design['key']] = self::normalizeColor(is_string($value) ? $value : null) ?? $design['accent'];
        }

        return $colors;
    }

    public static function accentFor(?Organization $organization, string $key): string
    {
        return self::colors($organization)[$key] ?? (self::find($key)['accent'] ?? '#18181b');
    }

    public static function setAccent(Organization $organization, string $key, string $accent): ?string
    {
        $key = self::normalize($key);
        $accent = self::normalizeColor($accent);
        if ($key === null || $accent === null) {
            return null;
        }

        $settings = $organization->settings ?? [];
        $colors = is_array($settings['design_colors'] ?? null) ? $settings['design_colors'] : [];
        $colors[$key] = $accent;
        $settings['design_colors'] = $colors;
        $organization->forceFill(['settings' => $settings])->save();

        return $accent;
    }

    public static function resetAccent(Organization $organization, string $key): ?string
    {
        $key = self::normalize($key);
        $design = self::find($key);
        if ($design === null) {
            return null;
        }

        $settings = $organization->settings ?? [];
        $colors = is_array($settings['design_colors'] ?? null) ? $settings['design_colors'] : [];
        unset($colors[$key]);
        $settings['design_colors'] = $colors;
        $organization->forceFill(['settings' => $settings])->save();

        return $design['accent'];
    }

    public static function normalizeColor(?string $color): ?string
    {
        $color = strtolower(trim((string) $color));

        return preg_match('/^#[0-9a-f]{6}$/', $color) === 1 ? $color : null;
    }

    /**
     * Catalog shared with the app. Fragments still contain {{content}}, {{brand}}, and {{accent}}.
     *
     * @return list<array{key: string, name: string, description: string, accent: string, background: string, fragment: string}>
     */
    public static function forClient(?Organization $organization = null): array
    {
        $colors = self::colors($organization);

        return array_map(function (array $design) use ($colors) {
            $design['default_accent'] = $design['accent'];
            $design['accent'] = $colors[$design['key']] ?? $design['accent'];

            return $design;
        }, self::all());
    }

    /**
     * Frame HTML in a design. Unknown or empty keys return the HTML unchanged.
     * Already-framed HTML is left alone so a forward or retry cannot nest a second shell.
     */
    /**
     * Keep pictures inside the email column. Gmail otherwise paints a wide
     * image at full size and clips the words underneath it.
     */
    public static function fitImages(string $html): string
    {
        if ($html === '' || ! str_contains(strtolower($html), '<img')) {
            return $html;
        }

        $fitted = preg_replace_callback('/<img\b([^>]*?)\/?>/i', function (array $match): string {
            $attrs = preg_replace('/\s(?:width|height)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $match[1]) ?? $match[1];
            $rule = 'display:block;width:100%;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;';
            if (preg_match('/\sstyle\s*=\s*("|\')(.*?)\1/i', $attrs, $style) === 1) {
                $merged = rtrim($style[2], '; ').';'.$rule;
                $attrs = preg_replace('/\sstyle\s*=\s*("|\')(.*?)\1/i', ' style="'.$merged.'"', $attrs, 1) ?? $attrs;
            } else {
                $attrs .= ' style="'.$rule.'"';
            }

            return '<img'.rtrim($attrs).' width="520">';
        }, $html);

        return is_string($fitted) ? $fitted : $html;
    }

    public static function wrap(mixed $key, string $html, string $brand = '', ?Organization $organization = null): string
    {
        $html = self::fitImages($html);
        $design = self::find($key);
        if ($design === null) {
            return $html;
        }

        if (preg_match('/data-md-design=/', substr($html, 0, 1500)) === 1) {
            return $html;
        }

        $fragment = str_replace(
            ['{{brand}}', '{{accent}}', '{{content}}'],
            [e($brand), self::accentFor($organization, $design['key']), $html],
            $design['fragment'],
        );

        return '<!DOCTYPE html><html data-md-design="'.e($design['key']).'"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<style>img{display:block;width:100%;max-width:100%;height:auto;border:0;}</style></head>'
            .'<body style="margin:0;padding:0;background:'.e($design['background']).';">'
            .$fragment
            .'</body></html>';
    }

    /**
     * @return array{key: string, name: string, description: string, accent: string, background: string, fragment: string}
     */
    private static function aurora(): array
    {
        return [
            'key' => 'aurora',
            'name' => 'Aurora',
            'description' => 'Night header, cyan mark, and a bright letter card.',
            'accent' => '#67e8f9',
            'background' => '#0b1220',
            'fragment' => <<<'HTML'
<table role="presentation" data-md-design="aurora" width="100%" cellpadding="0" cellspacing="0" style="background:#0b1220;">
<tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
<tr><td style="padding:0 8px 18px;font-family:Segoe UI,Helvetica,Arial,sans-serif;">
<div style="font-size:12px;letter-spacing:0.18em;text-transform:uppercase;color:{{accent}};">{{brand}}</div>
</td></tr>
<tr><td style="background:#ffffff;padding:32px 28px;font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.65;color:#18181b;">
{{content}}
</td></tr>
<tr><td style="padding:18px 8px 0;font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.5;color:#94a3b8;">
Sent by {{brand}}
</td></tr>
</table>
</td></tr>
</table>
HTML,
        ];
    }

    /**
     * @return array{key: string, name: string, description: string, accent: string, background: string, fragment: string}
     */
    private static function editorial(): array
    {
        return [
            'key' => 'editorial',
            'name' => 'Editorial',
            'description' => 'Cream paper, a serif letter, and a single rule.',
            'accent' => '#1c1917',
            'background' => '#f4efe6',
            'fragment' => <<<'HTML'
<table role="presentation" data-md-design="editorial" width="100%" cellpadding="0" cellspacing="0" style="background:#f4efe6;">
<tr><td align="center" style="padding:36px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
<tr><td style="padding:0 8px 14px;font-family:Georgia,Times New Roman,serif;font-size:13px;letter-spacing:0.14em;text-transform:uppercase;color:{{accent}};">{{brand}}</td></tr>
<tr><td style="border-top:1px solid {{accent}};padding:28px 8px 8px;font-family:Georgia,Times New Roman,serif;font-size:18px;line-height:1.7;color:#1c1917;">
{{content}}
</td></tr>
<tr><td style="padding:8px 8px 0;font-family:Georgia,Times New Roman,serif;font-size:12px;color:#a8a29e;">{{brand}}</td></tr>
</table>
</td></tr>
</table>
HTML,
        ];
    }

    /**
     * @return array{key: string, name: string, description: string, accent: string, background: string, fragment: string}
     */
    private static function signal(): array
    {
        return [
            'key' => 'signal',
            'name' => 'Signal',
            'description' => 'A crisp product note with an indigo rail.',
            'accent' => '#4f46e5',
            'background' => '#eef2ff',
            'fragment' => <<<'HTML'
<table role="presentation" data-md-design="signal" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2ff;">
<tr><td align="center" style="padding:28px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;">
<tr><td style="width:6px;background:{{accent}};border-radius:14px 0 0 14px;font-size:0;line-height:0;">&nbsp;</td>
<td style="padding:28px 26px;font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.6;color:#18181b;">
<div style="margin:0 0 16px;font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:{{accent}};">{{brand}}</div>
{{content}}
</td></tr>
</table>
</td></tr>
</table>
HTML,
        ];
    }

    /**
     * @return array{key: string, name: string, description: string, accent: string, background: string, fragment: string}
     */
    private static function linen(): array
    {
        return [
            'key' => 'linen',
            'name' => 'Linen',
            'description' => 'Warm paper, a soft card, and a rust wordmark.',
            'accent' => '#c2410c',
            'background' => '#f3e6d6',
            'fragment' => <<<'HTML'
<table role="presentation" data-md-design="linen" width="100%" cellpadding="0" cellspacing="0" style="background:#f3e6d6;">
<tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:540px;background:#fffaf5;border-radius:20px;">
<tr><td style="padding:28px 28px 8px;font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:13px;font-weight:700;letter-spacing:0.04em;color:{{accent}};">{{brand}}</td></tr>
<tr><td style="padding:8px 28px 28px;font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.65;color:#44403c;">
{{content}}
</td></tr>
</table>
</td></tr>
</table>
HTML,
        ];
    }

    /**
     * @return array{key: string, name: string, description: string, accent: string, background: string, fragment: string}
     */
    private static function midnight(): array
    {
        return [
            'key' => 'midnight',
            'name' => 'Midnight',
            'description' => 'A dark letter with a violet edge.',
            'accent' => '#a78bfa',
            'background' => '#09090b',
            'fragment' => <<<'HTML'
<table role="presentation" data-md-design="midnight" width="100%" cellpadding="0" cellspacing="0" style="background:#09090b;">
<tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#18181b;border-radius:16px;border-top:3px solid {{accent}};">
<tr><td style="padding:26px 28px 8px;font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:12px;letter-spacing:0.16em;text-transform:uppercase;color:{{accent}};">{{brand}}</td></tr>
<tr><td style="padding:8px 28px 28px;font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.65;color:#f4f4f5;">
{{content}}
</td></tr>
</table>
</td></tr>
</table>
HTML,
        ];
    }
}
