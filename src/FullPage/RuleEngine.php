<?php

declare(strict_types=1);

namespace Fphp\FullPage;

use Fphp\Config;

class RuleEngine
{
    protected Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Check if the current request can be served from cache or cached.
     *
     * @return array [bool $cacheable, string $reason]
     */
    public function isRequestCacheable(): array
    {
        // 1. Only GET and HEAD requests can be cached
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return [false, 'Non-GET method: ' . $method];
        }

        // 2. Check bypass query parameters (debugging, searches, previews, customizers)
        if (isset($_GET['fphp_nocache']) || isset($_GET['nocache']) || isset($_GET['fphp_bypass'])) {
            return [false, 'Bypass query parameter detected'];
        }
        if (isset($_GET['preview']) || isset($_GET['s']) || isset($_GET['customize_changeset_uuid'])) {
            return [false, 'Dynamic search or preview parameter detected'];
        }

        // 2b. Bypass AJAX requests (prevent caching partial JSON/HTML intended for live widgets)
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return [false, 'Bypass AJAX XMLHttpRequest'];
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // 3. Check excluded URL patterns
        $excludedUrls = $this->config->get('page_cache.excluded_urls', []);
        foreach ($excludedUrls as $pattern) {
            if (@preg_match($pattern, $path)) {
                return [false, 'Excluded URL pattern: ' . $pattern];
            }
        }

        // 4. Check bypass cookies (logged-in users, carts, admin sessions)
        $bypassCookies = $this->config->get('page_cache.bypass_cookies', []);
        foreach ($_COOKIE as $cookieName => $cookieValue) {
            foreach ($bypassCookies as $prefix) {
                if (str_starts_with($cookieName, $prefix)) {
                    return [false, 'Bypass cookie present: ' . $cookieName];
                }
            }
        }

        return [true, 'OK'];
    }

    /**
     * Sanitize Host header to prevent Cache Poisoning attacks.
     *
     * @param string|null $rawHost
     * @return string Normalized, safe host
     */
    public function sanitizeHost(?string $rawHost): string
    {
        $host = strtolower(trim((string)$rawHost));

        // Strip port if present (e.g., example.com:8080 -> example.com)
        $host = (string)preg_replace('/:\d+$/', '', $host);

        // Strip any characters that are not valid hostname characters
        $host = (string)preg_replace('/[^a-z0-9\.\-]/', '', $host);
        $host = trim($host, '.');

        // Check against trusted_hosts whitelist if configured
        $trusted = (array)$this->config->get('page_cache.trusted_hosts', []);
        if (!empty($trusted)) {
            if (in_array($host, $trusted, true)) {
                return $host;
            }
            // Fallback to first trusted host if Host header is untrusted/forged
            return (string)$trusted[0];
        }

        if ($host === '') {
            $fallback = strtolower((string)($_SERVER['SERVER_NAME'] ?? 'localhost'));
            $fallback = (string)preg_replace('/:\d+$/', '', $fallback);
            $fallback = (string)preg_replace('/[^a-z0-9\.\-]/', '', $fallback);
            return $fallback !== '' ? $fallback : 'localhost';
        }

        return $host;
    }

    /**
     * Generate normalized unique cache key for the current HTTP request.
     *
     * @return string
     */
    public function generateCacheKey(): string
    {
        $host = $this->sanitizeHost($_SERVER['HTTP_HOST'] ?? null);
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Normalize query parameters (strip analytics/tracking parameters)
        $queryParams = [];
        if (!empty($_SERVER['QUERY_STRING'])) {
            parse_str($_SERVER['QUERY_STRING'], $queryParams);
            $stripList = $this->config->get('page_cache.strip_query_params', []);
            foreach ($stripList as $param) {
                unset($queryParams[$param]);
            }
            ksort($queryParams);
        }

        $normalizedQuery = http_build_query($queryParams);
        $fullUri = $path . ($normalizedQuery !== '' ? '?' . $normalizedQuery : '');

        // Include HTTPS status in key
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        return 'page:' . hash('xxh128', $scheme . '://' . $host . $fullUri);
    }
}
