<?php

declare(strict_types=1);

namespace Fphp\FullPage;

class HeaderManager
{
    /**
     * Generate strong ETag from content.
     *
     * @param string $content
     * @return string
     */
    public static function generateEtag(string $content): string
    {
        return '"' . hash('xxh128', $content) . '"';
    }

    /**
     * Check if client's If-None-Match matches the given ETag.
     *
     * @param string $etag
     * @return bool
     */
    public static function matchesIfNoneMatch(string $etag): bool
    {
        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] 
            ?? $_SERVER['REDIRECT_HTTP_IF_NONE_MATCH'] 
            ?? '';

        if (empty($ifNoneMatch) && function_exists('getallheaders')) {
            $all = getallheaders();
            if (is_array($all)) {
                foreach ($all as $k => $v) {
                    if (strcasecmp((string)$k, 'If-None-Match') === 0) {
                        $ifNoneMatch = (string)$v;
                        break;
                    }
                }
            }
        }

        if (empty($ifNoneMatch)) {
            return false;
        }

        $cleanEtag = trim($etag, '"');
        $rawEtags = explode(',', $ifNoneMatch);
        foreach ($rawEtags as $clientTag) {
            $clientTag = trim($clientTag);
            if ($clientTag === '*') {
                return true;
            }
            // Strip W/ (weak tag) and quotes
            $clientTag = preg_replace('/^W\//i', '', $clientTag);
            $clientTag = trim($clientTag, "\"'\t\n\r ");
            if ($clientTag === $cleanEtag) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if client's If-Modified-Since matches or precedes cache creation time (RFC 9110).
     *
     * @param int|null $lastModifiedTimestamp
     * @return bool
     */
    public static function matchesIfModifiedSince(?int $lastModifiedTimestamp): bool
    {
        if ($lastModifiedTimestamp === null || $lastModifiedTimestamp <= 0) {
            return false;
        }

        $ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] 
            ?? $_SERVER['REDIRECT_HTTP_IF_MODIFIED_SINCE'] 
            ?? '';

        if (empty($ifModifiedSince) && function_exists('getallheaders')) {
            $all = getallheaders();
            if (is_array($all)) {
                foreach ($all as $k => $v) {
                    if (strcasecmp((string)$k, 'If-Modified-Since') === 0) {
                        $ifModifiedSince = (string)$v;
                        break;
                    }
                }
            }
        }

        if (empty($ifModifiedSince)) {
            return false;
        }

        // Semicolon might separate length or extra directives
        $parts = explode(';', $ifModifiedSince, 2);
        $clientTime = @strtotime(trim($parts[0]));

        if ($clientTime === false || $clientTime <= 0) {
            return false;
        }

        // If client timestamp is greater than or equal to resource modification time, resource is unchanged!
        return $clientTime >= $lastModifiedTimestamp;
    }

    /**
     * Send HTTP 304 Not Modified header and exit immediately.
     *
     * @param string $etag
     * @param float $executionTimeUs
     * @param int|null $lastModified
     * @return void
     */
    public static function sendNotModified(string $etag, float $executionTimeUs, ?int $lastModified = null): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code(304);
        if (!empty($etag)) {
            header('ETag: ' . $etag);
        }
        if ($lastModified !== null && $lastModified > 0) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s \G\M\T', $lastModified));
        }
        header('X-FPHP-Cache: HIT (304 Not Modified)');
        header('X-FPHP-Time: ' . round($executionTimeUs / 1000, 3) . 'ms (' . round($executionTimeUs) . 'µs)');
        header('Cache-Control: public, max-age=1800, stale-while-revalidate=60');
        exit;
    }

    /**
     * Send cache headers for a cache HIT.
     *
     * @param string $driver
     * @param float $executionTimeUs
     * @param string $etag
     * @param int $ttl
     * @param bool $isCompressed
     * @return void
     */
    public static function sendHitHeaders(
        string $driver,
        float $executionTimeUs,
        ?string $etag = '',
        int $ttl = 1800,
        bool $isCompressed = false
    ): void {
        if (headers_sent()) {
            return;
        }

        header('X-FPHP-Cache: HIT (' . strtoupper($driver) . ')');
        header('X-FPHP-Time: ' . round($executionTimeUs / 1000, 3) . 'ms (' . round($executionTimeUs) . 'µs)');
        header('X-FPHP-Speedup: ~' . max(2, round(25000 / max(1, $executionTimeUs))) . 'x');
        header('Cache-Control: public, max-age=' . $ttl . ', stale-while-revalidate=60');
        header('Access-Control-Expose-Headers: X-FPHP-Cache, X-FPHP-Time, X-FPHP-Speedup, X-FPHP-Generation-Time, ETag');

        if (!empty($etag)) {
            header('ETag: ' . $etag);
        }

        header('Vary: Accept-Encoding');
    }
}
