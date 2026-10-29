<?php

declare(strict_types=1);

namespace Fphp\Optimizer;

/**
 * High-Speed Compression & Client Negotiation Handler
 */
class Compression
{
    /**
     * Check if the client browser supports Gzip compression.
     *
     * @return bool
     */
    public static function clientSupportsGzip(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
        return str_contains($accept, 'gzip');
    }

    /**
     * Check if the client browser supports Brotli compression.
     */
    public static function clientSupportsBrotli(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
        return str_contains($accept, 'br');
    }

    /**
     * Check if the client browser supports Deflate compression.
     */
    public static function clientSupportsDeflate(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
        return str_contains($accept, 'deflate');
    }

    /**
     * Negotiate best supported encoding between client and server.
     * Order of preference: br (Brotli) > gzip > deflate > null.
     */
    public static function negotiateEncoding(): ?string
    {
        if (self::clientSupportsBrotli() && function_exists('brotli_compress')) {
            return 'br';
        }
        if (self::clientSupportsGzip() && function_exists('gzencode')) {
            return 'gzip';
        }
        if (self::clientSupportsDeflate() && function_exists('gzdeflate')) {
            return 'deflate';
        }
        return null;
    }

    /**
     * Compress raw string with Brotli (if extension available).
     */
    public static function brotli(string $data, int $quality = 4): string|false
    {
        if (!function_exists('brotli_compress')) {
            return false;
        }

        return @brotli_compress($data, $quality);
    }

    /**
     * Decompress Brotli data.
     */
    public static function unbrotli(string $data): string|false
    {
        if (!function_exists('brotli_uncompress')) {
            return false;
        }

        return @brotli_uncompress($data);
    }

    /**
     * Compress raw string with Gzip.
     *
     * @param string $data
     * @param int $level 1-9
     * @return string|false
     */
    public static function gzip(string $data, int $level = 6): string|false
    {
        if (!function_exists('gzencode')) {
            return false;
        }

        return gzencode($data, $level);
    }

    /**
     * Decompress Gzip data.
     *
     * @param string $data
     * @return string|false
     */
    public static function gunzip(string $data): string|false
    {
        if (!function_exists('gzdecode')) {
            return false;
        }

        return @gzdecode($data);
    }
}
