<?php

declare(strict_types=1);

namespace Fphp;

class Config
{
    protected array $settings = [];

    public function __construct(array $customSettings = [])
    {
        $defaultSettings = [
            'enabled' => true,
            'driver' => 'hybrid', // 'hybrid' (APCu + Redis/File fallback), 'apcu', 'redis', 'file', 'memory'
            'cache_dir' => dirname(__DIR__) . '/cache',
            'prefix' => 'fphp:',
            'default_ttl' => 3600,

            // Remote Redis Cluster Settings (Optional)
            'redis' => [
                'enabled' => false,
                'host' => '127.0.0.1',
                'port' => 6379,
                'password' => null,
                'database' => 0,
                'timeout' => 1.5,
            ],

            // Full Page Microcache Settings
            'page_cache' => [
                'enabled' => true,
                'ttl' => 1800,
                'strip_query_params' => [
                    'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
                    'fbclid', 'gclid', 'ref', 'source', '_t', 'timestamp', 'cb'
                ],
                'excluded_urls' => [
                    '#/admin#i',
                    '#/control-panel#i',
                    '#/cart#i',
                    '#/checkout#i',
                    '#/login#i',
                    '#/logout#i',
                    '#/register#i',
                    '#/api/auth#i'
                ],
                'bypass_cookies' => [
                    'session_logged_in_',
                    'user_logged_in_',
                    'PHPSESSID_LOGGED_IN',
                    'fphp_bypass',
                    'admin_session'
                ],
                'send_headers' => true,
                'etag_enabled' => true,
                'bytecode_cache' => true, // OPcache Bytecode Transmutation: compiles pages into executable C-memory objects
                'hole_punching' => true,  // Streaming dynamic holes / island architecture for personalized slots
                'stale_while_revalidate' => 60,
                'stale_if_error' => true, // RFC 5861 Disaster recovery: serve stale cache on backend 500 error / crash
                'purge_enabled' => true,  // RFC HTTP PURGE / BAN method interception
                'purge_key' => null,      // Optional secret key for PURGE authentication
                'trusted_hosts' => []     // Allowed hosts whitelist for Host header poisoning protection
            ],

            // Content Optimizations
            'optimizer' => [
                'minify_html' => true,
                'minify_css' => true,
                'remove_html_comments' => true,
                'collapse_whitespace' => true,
                'gzip_compression' => true,
                'compression_level' => 6
            ],

            // Cache Warmer & Predictive Sequence Prefetcher
            'warmer' => [
                'predictive_markov' => true,
                'markov_threshold' => 0.20
            ],

            // W3C Speculation Rules (0.00 ms Instant Navigation)
            'speculation_rules' => [
                'enabled' => true,
                'prerender_threshold' => 0.40,
                'prefetch_threshold' => 0.15,
                'auto_inject' => true
            ],

            // Autonomous Reactive Data Lineage Graph (DAG)
            'lineage' => [
                'enabled' => true
            ],

            // Cybernetic PID Homeostasis Controller
            'adaptive_pid' => [
                'enabled' => true,
                'target_memory_ratio' => 0.70,
                'kp' => 1.2,
                'ki' => 0.05,
                'kd' => 0.25
            ],

            // Profiling & Stats
            'profiler' => [
                'enabled' => true,
                'detailed_metrics' => true
            ]
        ];

        $this->settings = $this->mergeRecursive($defaultSettings, $customSettings);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (str_contains($key, '.')) {
            $parts = explode('.', $key);
            $current = $this->settings;
            foreach ($parts as $part) {
                if (!is_array($current) || !array_key_exists($part, $current)) {
                    return $default;
                }
                $current = $current[$part];
            }
            return $current;
        }

        return $this->settings[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        if (str_contains($key, '.')) {
            $parts = explode('.', $key);
            $current = &$this->settings;
            foreach ($parts as $part) {
                if (!isset($current[$part]) || !is_array($current[$part])) {
                    $current[$part] = [];
                }
                $current = &$current[$part];
            }
            $current = $value;
        } else {
            $this->settings[$key] = $value;
        }
    }

    public function all(): array
    {
        return $this->settings;
    }

    protected function mergeRecursive(array $base, array $replacement): array
    {
        foreach ($replacement as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeRecursive($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
