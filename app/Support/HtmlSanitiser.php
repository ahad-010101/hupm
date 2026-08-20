<?php

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Server-side allowlist sanitisation for admin-authored notice bodies.
 * [TDD §6.2, WP-20]
 *
 * This is the only thing standing behind the only `dangerouslySetInnerHTML` in
 * the product, so it runs **on the way in**, not on the way out. What is stored
 * is already safe; the rendering side is not where the guarantee lives, because
 * a second render added later would not know to sanitise.
 *
 * HTMLPurifier rather than a regex or a hand-rolled DOM walk. Stripping
 * `<script>` is the easy fifth of the problem — the rest is `javascript:` in an
 * href, `onerror` on an image, CSS `expression()`, mutation XSS where the
 * browser re-parses almost-valid markup into something else, and namespace
 * confusion with SVG and MathML. A sanitiser is a parser, and writing one to
 * protect the single most exposed surface in the application would be the wrong
 * place to save a dependency.
 *
 * The allowlist is deliberately dull: this is a letter to a resident, not a
 * newsletter. No images, no tables, no classes, no styles.
 */
class HtmlSanitiser
{
    /** Enough for a letter. Anything richer belongs in an attachment. */
    private const ALLOWED = 'p,br,strong,b,em,i,u,ul,ol,li,h2,h3,blockquote,a[href|title]';

    private ?HTMLPurifier $purifier = null;

    public function clean(string $html): string
    {
        return $this->purifier()->purify($html);
    }

    /**
     * A plain-text rendering, for the email body and for previews.
     *
     * Taken from the sanitised HTML rather than the raw input, so it cannot
     * carry anything the allowlist rejected.
     */
    public function toText(string $html): string
    {
        $safe = $this->clean($html);
        $spaced = preg_replace('~<(?:/p|br\s*/?|/li|/h2|/h3)>~i', "\n", $safe) ?? $safe;

        return trim(html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function purifier(): HTMLPurifier
    {
        if ($this->purifier !== null) {
            return $this->purifier;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', self::ALLOWED);

        // http, https and mailto only. `javascript:` and `data:` are the two
        // that turn a link into an execution, and a scheme allowlist is the
        // only reliable way to exclude them — blocklists lose to `jAvAsCrIpT:`
        // and to control characters inside the scheme.
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('HTML.TargetBlank', true);
        $config->set('Attr.AllowedRel', ['noopener', 'noreferrer']);

        // No inline CSS at all. `expression()`, `url(javascript:…)` and
        // position tricks that overlay a page all arrive through style.
        $config->set('CSS.AllowedProperties', []);

        // Cache compiled definitions somewhere writable. Shared hosting has no
        // guarantee about the vendor directory being writable, and a sanitiser
        // that cannot cache must still run rather than fail closed on a page.
        $cache = storage_path('app/htmlpurifier');

        if (! is_dir($cache)) {
            @mkdir($cache, 0755, true);
        }

        $config->set('Cache.SerializerPath', is_writable($cache) ? $cache : null);

        return $this->purifier = new HTMLPurifier($config);
    }
}
