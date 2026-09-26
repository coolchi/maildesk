<?php

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Cleans email HTML before it is shown in the app.
 *
 * Removes scripts, event handlers (onclick, onerror, ...), forms, embeds,
 * iframes and javascript:/data: URLs, while keeping the inline styles and
 * table attributes that email layouts rely on. Every link is forced to open
 * in a new tab with rel="noopener noreferrer".
 *
 * The raw HTML stays untouched in the database; this runs on output.
 */
class EmailHtmlSanitizer
{
    private static ?HTMLPurifier $purifier = null;

    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        return self::purifier()->purify($html);
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier) {
            return self::$purifier;
        }

        $cache = storage_path('framework/cache/htmlpurifier');
        if (! is_dir($cache)) {
            @mkdir($cache, 0755, true);
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', is_writable($cache) ? $cache : null);
        $config->set('HTML.Doctype', 'XHTML 1.0 Transitional');
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.TargetNoopener', true);
        $config->set('HTML.TargetNoreferrer', true);
        $config->set('HTML.ForbiddenElements', ['form', 'input', 'button', 'textarea', 'select']);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true, 'cid' => true]);
        $config->set('CSS.AllowTricky', true);
        $config->set('CSS.Proprietary', true);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('AutoFormat.RemoveEmpty', false);

        return self::$purifier = new HTMLPurifier($config);
    }
}
