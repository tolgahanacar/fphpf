<?php

declare(strict_types=1);

namespace Fphp\Optimizer;

/**
 * High-Speed HTML & CSS Minifier
 * 
 * Safely compresses HTML markup while protecting <pre>, <textarea>, and <script> contents.
 */
class Minifier
{
    /**
     * Minify HTML content safely.
     *
     * @param string $html
     * @param array $options
     * @return string
     */
    public static function html(string $html, array $options = []): string
    {
        if (empty(trim($html))) {
            return $html;
        }

        $removeComments = $options['remove_comments'] ?? true;
        $minifyCss = $options['minify_css'] ?? true;

        // Placeholders for preserved blocks (<pre>, <textarea>, <script>)
        $protectedBlocks = [];
        $placeholderPrefix = '###FPHP_PROTECTED_BLOCK_';

        // 1. Protect <pre> blocks
        $html = preg_replace_callback('#<pre\b[^>]*>.*?</pre>#si', function ($matches) use (&$protectedBlocks, $placeholderPrefix) {
            $key = $placeholderPrefix . count($protectedBlocks) . '###';
            $protectedBlocks[$key] = $matches[0];
            return $key;
        }, $html);

        // 2. Protect <textarea> blocks
        $html = preg_replace_callback('#<textarea\b[^>]*>.*?</textarea>#si', function ($matches) use (&$protectedBlocks, $placeholderPrefix) {
            $key = $placeholderPrefix . count($protectedBlocks) . '###';
            $protectedBlocks[$key] = $matches[0];
            return $key;
        }, $html);

        // 3. Protect <script> blocks (and minify JSON-LD or simple scripts if safe)
        $html = preg_replace_callback('#<script\b[^>]*>.*?</script>#si', function ($matches) use (&$protectedBlocks, $placeholderPrefix) {
            $key = $placeholderPrefix . count($protectedBlocks) . '###';
            $protectedBlocks[$key] = $matches[0];
            return $key;
        }, $html);

        // 4. Minify inline <style> blocks if requested
        if ($minifyCss) {
            $html = preg_replace_callback('#<style\b[^>]*>(.*?)</style>#si', function ($matches) {
                return '<style>' . self::css($matches[1]) . '</style>';
            }, $html);
        }

        // 5. Remove HTML comments (except IE conditionals and special markers)
        if ($removeComments) {
            $html = preg_replace('/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '', $html);
        }

        // 6. Collapse redundant whitespaces, tabs, newlines between HTML tags
        $html = preg_replace('/>\s+</', '> <', $html);
        $html = preg_replace('/\s{2,}/', ' ', $html);

        // 7. Restore protected blocks
        if (!empty($protectedBlocks)) {
            $html = strtr($html, $protectedBlocks);
        }

        return trim($html);
    }

    /**
     * Minify CSS strings.
     *
     * @param string $css
     * @return string
     */
    public static function css(string $css): string
    {
        // Strip CSS comments
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        // Remove spaces around separators
        $css = preg_replace('/\s*([{}|:;,>+~])\s*/', '$1', $css);
        // Remove trailing semicolons in blocks
        $css = str_replace(';}', '}', $css);
        // Collapse spaces
        $css = preg_replace('/\s+/', ' ', $css);

        return trim($css);
    }
}
