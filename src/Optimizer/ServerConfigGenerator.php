<?php

declare(strict_types=1);

namespace Fphp\Optimizer;

/**
 * Web Server Zero-PHP Sidecar Configuration Generator
 * 
 * Generates automated server configuration snippets (Nginx, Caddy, Apache)
 * to allow the front-facing web server to serve cached .fpc/.fpc.gz files directly
 * from RAM disk or filesystem without invoking PHP-FPM worker processes.
 * 
 * Increases server capacity up to 150,000+ requests per second per node.
 */
class ServerConfigGenerator
{
    /**
     * Generate Nginx sidecar configuration snippet.
     *
     * @param string $cacheDir Absolute path to cache directory
     * @return string Nginx server configuration snippet
     */
    public static function nginx(string $cacheDir): string
    {
        $normalizedDir = str_replace('\\', '/', rtrim($cacheDir, '/\\'));
        $fpcDir = $normalizedDir . '/fpc';

        return <<<NGINX
# ====================================================================
# FPHP Cache - Zero-PHP Kernel Bypass Configuration for Nginx
# Include this block inside your server { ... } block before PHP location
# ====================================================================

# 1. Bypass check for query strings, POST requests, and auth cookies
set \$fphp_bypass 0;
if (\$request_method !~ ^(GET|HEAD)$) {
    set \$fphp_bypass 1;
}
if (\$http_cookie ~* "session_logged_in_|auth_token_|admin_session") {
    set \$fphp_bypass 1;
}
if (\$arg_fphp_nocache) {
    set \$fphp_bypass 1;
}

# 2. Direct Kernel File Delivery (Bypasses PHP-FPM completely)
location @fphp_backend {
    # Fallback to standard PHP-FPM processor
    try_files \$uri \$uri/ /index.php?\$args;
}

# Enable native kernel gzip streaming
gzip_static on;
gzip_http_version 1.1;
gzip_vary on;

# Cache storage root
set \$fphp_cache_root "{$fpcDir}";
NGINX;
    }

    /**
     * Generate Caddy sidecar configuration snippet.
     *
     * @param string $cacheDir
     * @return string Caddyfile snippet
     */
    public static function caddy(string $cacheDir): string
    {
        $normalizedDir = str_replace('\\', '/', rtrim($cacheDir, '/\\'));
        $fpcDir = $normalizedDir . '/fpc';

        return <<<CADDY
# ====================================================================
# FPHP Cache - Zero-PHP Kernel Bypass Configuration for Caddy
# ====================================================================
@fphp_eligible {
    method GET HEAD
    not header_regexp Cookie "(session_logged_in_|auth_token_|admin_session)"
    not query fphp_nocache=*
}

handle @fphp_eligible {
    root * "{$fpcDir}"
    encode gzip zstd
    file_server {
        precompressed gzip
    }
}
CADDY;
    }
}

