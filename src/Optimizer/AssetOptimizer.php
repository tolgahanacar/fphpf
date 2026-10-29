<?php

declare(strict_types=1);

namespace Fphp\Optimizer;

/**
 * Enterprise Asset Optimizer & HTTP 103 Early Hints Generator
 * 
 * Extracts critical stylesheets, Google fonts, and hero assets to inject
 * HTTP Link preload headers and <link rel="preload"> tags into HTML.
 */
class AssetOptimizer
{
    /**
     * Analyze HTML and generate Link preload headers.
     *
     * @param string $html
     * @return array Array of Link header values
     */
    public static function extractPreloadLinks(string $html): array
    {
        $links = [];

        // 1. Detect external CSS stylesheets
        if (preg_match_all('#<link\b[^>]*rel=["\']stylesheet["\'][^>]*href=["\']([^"\']+)["\']#i', $html, $matches)) {
            foreach (array_unique($matches[1]) as $cssUrl) {
                // Ignore external tracking or dynamic links
                if (!str_contains($cssUrl, 'livereload')) {
                    $links[] = "<{$cssUrl}>; rel=preload; as=style";
                }
            }
        }

        // 2. Detect Google Fonts and CDN domains for preconnect
        if (str_contains($html, 'fonts.googleapis.com') || str_contains($html, 'fonts.gstatic.com')) {
            $links[] = '<https://fonts.googleapis.com>; rel=preconnect';
            $links[] = '<https://fonts.gstatic.com>; rel=preconnect; crossorigin';
        }

        // 3. Detect critical woff2 web fonts
        if (preg_match_all('#url\(["\']?([^"\'\)]+\.woff2[^"\'\)]*)["\']?\)#i', $html, $fontMatches)) {
            foreach (array_slice(array_unique($fontMatches[1]), 0, 3) as $fontUrl) {
                $links[] = "<{$fontUrl}>; rel=preload; as=font; type=font/woff2; crossorigin";
            }
        }

        return $links;
    }

    /**
     * Dispatch HTTP Link headers for Early Hints / browser preloading.
     *
     * @param array $linkHeaders
     * @return void
     */
    public static function dispatchLinkHeaders(array $linkHeaders): void
    {
        if (headers_sent() || empty($linkHeaders)) {
            return;
        }

        foreach ($linkHeaders as $link) {
            header("Link: {$link}", false);
        }
    }

    /**
     * Inject preload links into <head> if not already present.
     *
     * @param string $html
     * @param array $links
     * @return string
     */
    public static function injectPreloadTags(string $html, array $links): string
    {
        if (empty($links) || !str_contains($html, '<head>')) {
            return $html;
        }

        $tagsHtml = "\n    <!-- FPHP Early Hints & Preload Assets -->\n";
        foreach ($links as $link) {
            if (preg_match('#<([^>]+)>;\s*rel=([^;]+)(?:;\s*as=([^;]+))?#i', $link, $m)) {
                $url = htmlspecialchars($m[1]);
                $rel = trim($m[2]);
                $as = isset($m[3]) ? ' as="' . htmlspecialchars(trim($m[3])) . '"' : '';
                $crossorigin = str_contains($link, 'crossorigin') ? ' crossorigin' : '';
                $tagsHtml .= "    <link rel=\"{$rel}\" href=\"{$url}\"{$as}{$crossorigin}>\n";
            }
        }

        return str_replace('<head>', "<head>{$tagsHtml}", $html);
    }
}
