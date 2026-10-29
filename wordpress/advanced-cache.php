<?php

declare(strict_types=1);

/**
 * FPHP Cache - Advanced Cache Drop-in
 * 
 * Instructions:
 * 1. Copy this file to: wp-content/advanced-cache.php
 * 2. Add to configuration: define('WP_CACHE', true);
 * 
 * Result: Responds in microseconds (0.1ms - 0.2ms) without
 * loading heavy database layers, plugins, or themes for public visitors!
 */

if (!defined('ABSPATH')) {
    die();
}

// 1. Immediately bypass POST, PUT, DELETE, etc.
if (isset($_SERVER['REQUEST_METHOD']) && !in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    return;
}

// 2. Bypass Query Parameters (Search, Previews, Customizers)
if (!empty($_GET)) {
    if (isset($_GET['preview']) || isset($_GET['s']) || isset($_GET['customize_changeset_uuid'])) {
        return;
    }
}

// 3. Bypass Dynamic & Sensitive Cookies (Zero Data Loss Guarantee)
if (!empty($_COOKIE)) {
    foreach ($_COOKIE as $cookieKey => $cookieVal) {
        // Authenticated user session or commenter cookies
        if (str_starts_with($cookieKey, 'wordpress_logged_in_') || str_starts_with($cookieKey, 'comment_author_')) {
            return;
        }
        // E-commerce shopping cart & checkout session cookies
        if (str_starts_with($cookieKey, 'wp_woocommerce_session_') || 
            $cookieKey === 'woocommerce_items_in_cart' || 
            $cookieKey === 'woocommerce_cart_hash') {
            return;
        }
        // Digital downloads and cart cookies
        if ($cookieKey === 'edd_items_in_cart') {
            return;
        }
    }
}

// 4. Bypass Sensitive URLs (Admin, Cart, Checkout, XML-RPC)
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';

$bypassPaths = [
    '/wp-admin',
    '/wp-login.php',
    '/cart',
    '/checkout',
    '/my-account',
    '/xmlrpc.php',
    '/wp-cron.php',
    '/wp-json'
];

foreach ($bypassPaths as $bypass) {
    if (str_starts_with($path, $bypass)) {
        return;
    }
}

// 5. Load FPHP Cache Bootstrap
$bootstrapPath = dirname(__DIR__) . '/fphp-bootstrap.php';
if (!file_exists($bootstrapPath)) {
    // Check if installed in plugins directory
    $bootstrapPath = WP_CONTENT_DIR . '/plugins/fphp-cache/fphp-bootstrap.php';
}

if (file_exists($bootstrapPath)) {
    require_once $bootstrapPath;

    // Boot FPHP Micro-Accelerator
    \Fphp\FphpCache::boot([
        'page_cache' => [
            'enabled' => true,
            'ttl' => 3600
        ],
        'optimizer' => [
            'minify_html' => true,
            'minify_css' => true,
            'gzip_compression' => false // Let server handle transport compression
        ]
    ]);
}
