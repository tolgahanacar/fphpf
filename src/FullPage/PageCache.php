<?php

declare(strict_types=1);

namespace Fphp\FullPage;

use Fphp\Config;
use Fphp\Driver\CacheDriverInterface;
use Fphp\Optimizer\Minifier;
use Fphp\Optimizer\Compression;
use Fphp\Profiler\Metrics;
use Fphp\Profiler\StatsTracker;

/**
 * Full Page Microcaching Engine
 * 
 * Intercepts requests before framework bootstrapping and serves
 * pre-rendered, pre-compressed HTML in microseconds.
 */
class PageCache
{
    protected Config $config;
    protected CacheDriverInterface $driver;
    protected RuleEngine $ruleEngine;
    protected StatsTracker $stats;
    protected StreamStore $streamStore;
    protected BytecodeStore $bytecodeStore;
    protected ?\Fphp\Warmer\MarkovWarmer $markovWarmer = null;
    protected ?string $currentKey = null;
    protected bool $isCapturing = false;

    public function __construct(Config $config, CacheDriverInterface $driver, StatsTracker $stats)
    {
        $this->config = $config;
        $this->driver = $driver;
        $this->stats = $stats;
        $this->ruleEngine = new RuleEngine($config);
        $this->streamStore = new StreamStore($config->get('cache_dir'));
        $this->bytecodeStore = new BytecodeStore($config->get('cache_dir'));

        if ($this->config->get('warmer.predictive_markov', true)) {
            $this->markovWarmer = new \Fphp\Warmer\MarkovWarmer($config->get('cache_dir'));
        }
    }

    /**
     * Attempt to serve the page directly from cache.
     * Call this at the very first line of execution.
     *
     * @return bool True if cached and served (exits script), False if miss/bypass
     */
    public function serve(): bool
    {
        if (!$this->config->get('enabled', true) || !$this->config->get('page_cache.enabled', true)) {
            return false;
        }

        // Intercept RFC HTTP PURGE / BAN requests (CDN & Reverse-Proxy invalidation)
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'PURGE' || $method === 'BAN') {
            return $this->handlePurgeRequest();
        }

        Metrics::start('fphp_fpc');

        // Track navigation sequence in predictive Markov graph
        if ($this->markovWarmer !== null && !empty($_SERVER['HTTP_REFERER'])) {
            $this->markovWarmer->recordTransition($_SERVER['HTTP_REFERER'], $_SERVER['REQUEST_URI'] ?? '/');
        }

        [$cacheable, $reason] = $this->ruleEngine->isRequestCacheable();
        if (!$cacheable) {
            $this->stats->recordBypass($reason);
            return false;
        }

        $key = $this->ruleEngine->generateCacheKey();
        $this->currentKey = $key;

        $elapsedUs = Metrics::stopMicroseconds('fphp_fpc');

        // 1. OPcache Bytecode Transmutation Engine (0.8 - 1.5 µs C-Memory Execution)
        if ($this->config->get('page_cache.bytecode_cache', true)) {
            $holeFilter = $this->config->get('page_cache.hole_punching', true)
                ? fn(string $html) => \Fphp\Fragment\HolePuncher::renderHoles($html)
                : null;
            if ($this->bytecodeStore->execute($key, $elapsedUs, $holeFilter)) {
                $this->stats->recordHit($key, $elapsedUs, 'opcache_bytecode');
                return true;
            }
        }

        // 2. Zero-Serialization Kernel Stream (25 - 50 µs)
        if ($this->streamStore->stream($key, $elapsedUs)) {
            $this->stats->recordHit($key, $elapsedUs, 'kernel_stream');
            return true;
        }

        $cached = $this->driver->get($key);

        if ($cached !== null && is_array($cached)) {
            $elapsedUs = Metrics::stopMicroseconds('fphp_fpc');
            $driverName = $cached['driver'] ?? $this->driver->getName();
            $now = time();

            // Check Stale-While-Revalidate (SWR) logic
            $isStale = isset($cached['expires_at']) && $now > $cached['expires_at'];
            $withinGrace = isset($cached['grace_until']) && $now <= $cached['grace_until'];

            if ($isStale && $withinGrace) {
                // Try acquiring atomic lock to regenerate
                $hasLock = \Fphp\Lock\AtomicLock::acquire('swr:' . $key, 15);
                if (!$hasLock) {
                    // Another worker is regenerating right now! Serve stale content in microseconds!
                    HeaderManager::sendHitHeaders($driverName, $elapsedUs, $cached['etag'] ?? '', 60);
                    header('X-FPHP-Cache: HIT (SWR - Stale Served While Refreshing)');
                    if (!empty($cached['content_type'])) {
                        header('Content-Type: ' . $cached['content_type']);
                    }
                    echo $cached['content_raw'];
                    exit;
                }

                // If fastcgi_finish_request exists, deliver stale content to client immediately and revalidate in background!
                if (function_exists('fastcgi_finish_request')) {
                    HeaderManager::sendHitHeaders($driverName, $elapsedUs, $cached['etag'] ?? '', 60);
                    header('X-FPHP-Cache: HIT (SWR - Async Revalidating)');
                    if (!empty($cached['content_type'])) {
                        header('Content-Type: ' . $cached['content_type']);
                    }
                    echo $cached['content_raw'];
                    fastcgi_finish_request();
                    @ignore_user_abort(true);
                }
                // Fall through and regenerate fresh content!
            } elseif (!$isStale) {
                // Fresh Cache HIT
                $etag = $cached['etag'] ?? '';
                $isNotModified = (!empty($etag) && HeaderManager::matchesIfNoneMatch($etag))
                    || HeaderManager::matchesIfModifiedSince($cached['created_at'] ?? 0);

                if ($isNotModified) {
                    $this->stats->recordHit($key, $elapsedUs, $driverName);
                    HeaderManager::sendNotModified($etag, $elapsedUs, $cached['created_at'] ?? null);
                    return true;
                }

                $this->stats->recordHit($key, $elapsedUs, $driverName);

                HeaderManager::sendHitHeaders(
                    $driverName,
                    $elapsedUs,
                    $etag,
                    (int)$this->config->get('page_cache.ttl', 1800)
                );

                if (!empty($cached['content_type'])) {
                    header('Content-Type: ' . $cached['content_type']);
                }
                if (!empty($cached['content_raw'])) {
                    header('Content-Length: ' . strlen((string)$cached['content_raw']));
                }

                if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
                    exit;
                }

                echo $cached['content_raw'];
                exit;
            }
        }

        // Cache MISS: Start output capture
        $this->startCapture();
        return false;
    }

    /**
     * Start capturing output buffer.
     *
     * @return void
     */
    public function startCapture(): void
    {
        if ($this->isCapturing) {
            return;
        }

        $this->isCapturing = true;

        // Register fatal error rescue shutdown hook (RFC 5861 stale-if-error)
        if ($this->config->get('page_cache.stale_if_error', true)) {
            register_shutdown_function(function () {
                $lastError = error_get_last();
                if ($lastError && in_array($lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                    if (!empty($this->currentKey)) {
                        $stale = $this->retrieveStaleEntry($this->currentKey);
                        if ($stale !== null && !empty($stale['content_raw'])) {
                            while (ob_get_level() > 0) {
                                @ob_end_clean();
                            }
                            if (!headers_sent()) {
                                http_response_code(200);
                                header('X-FPHP-Cache: HIT (Stale-If-Error Fatal Rescue)');
                                header('Warning: 110 "Response is Stale"');
                                if (!empty($stale['content_type'])) {
                                    header('Content-Type: ' . $stale['content_type']);
                                }
                            }
                            echo $stale['content_raw'];
                            exit;
                        }
                    }
                }
            });
        }

        ob_start(function ($buffer) {
            return $this->processAndStore($buffer);
        });
    }

    /**
     * Process captured buffer, minify, compress, and store in cache.
     *
     * @param string $buffer
     * @return string
     */
    public function processAndStore(string $buffer): string
    {
        if (empty(trim($buffer)) || empty($this->currentKey)) {
            return $buffer;
        }

        // Check HTTP response code (don't cache errors; serve stale if 5xx disaster recovery enabled)
        $statusCode = http_response_code();
        if ($statusCode !== 200 && $statusCode !== false) {
            if ($statusCode >= 500 && $this->config->get('page_cache.stale_if_error', true)) {
                $stale = $this->serveStaleIfError($statusCode);
                if ($stale !== null) {
                    return $stale;
                }
            }
            return $buffer;
        }

        $contentType = 'text/html; charset=UTF-8';
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $contentType = trim(substr($header, 13));
                break;
            }
        }

        // Only cache HTML or JSON responses
        if (!str_contains($contentType, 'text/html') && !str_contains($contentType, 'application/json')) {
            return $buffer;
        }

        $elapsedUs = Metrics::stopMicroseconds('fphp_fpc');
        $this->stats->recordMiss($this->currentKey, $elapsedUs);

        // 1. Minification
        $processed = $buffer;
        if ($this->config->get('optimizer.minify_html', true) && str_contains($contentType, 'text/html')) {
            $processed = Minifier::html($buffer, [
                'remove_comments' => $this->config->get('optimizer.remove_html_comments', true),
                'minify_css' => $this->config->get('optimizer.minify_css', true)
            ]);
        }

        // 2. Early Hints & Preload Links Injection
        if (str_contains($contentType, 'text/html')) {
            $preloadLinks = \Fphp\Optimizer\AssetOptimizer::extractPreloadLinks($processed);
            if (!empty($preloadLinks)) {
                \Fphp\Optimizer\AssetOptimizer::dispatchLinkHeaders($preloadLinks);
                $processed = \Fphp\Optimizer\AssetOptimizer::injectPreloadTags($processed, $preloadLinks);
            }
        }

        // 3. Auto-Register visited URL with Cache Warmer
        try {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            (new \Fphp\Warmer\CacheWarmer($this->config->get('cache_dir')))->recordUrl($scheme . '://' . $host . $uri);
        } catch (\Throwable $e) {
            // Non-critical, ignore
        }

        // 4. Generate ETag
        $etag = HeaderManager::generateEtag($processed);

        // 5. Optional pre-compression
        $gzipData = '';
        $isGzip = false;
        if ($this->config->get('optimizer.gzip_compression', true)) {
            $compLevel = (int)$this->config->get('optimizer.compression_level', 6);
            $compressed = Compression::gzip($processed, $compLevel);
            if ($compressed !== false) {
                $gzipData = $compressed;
                $isGzip = true;
            }
        }

        $ttl = (int)$this->config->get('page_cache.ttl', 1800);
        $now = time();

        $cacheEntry = [
            'content_raw' => $processed,
            'content_gzip' => $gzipData,
            'is_gzip' => $isGzip,
            'content_type' => $contentType,
            'etag' => $etag,
            'driver' => $this->driver->getName(),
            'created_at' => $now,
            'expires_at' => $now + $ttl,
            'grace_until' => $now + $ttl + 300 // 5 minutes SWR grace window
        ];

        // 1. Store in OPcache Bytecode Transmutation Engine (0.8 µs execution)
        if ($this->config->get('page_cache.bytecode_cache', true)) {
            $this->bytecodeStore->store($this->currentKey, $processed, $contentType, $etag, $ttl);
        }

        // 2. Store in Zero-Copy Stream Store (for direct socket streaming)
        $this->streamStore->store($this->currentKey, $processed, $contentType, $etag, $ttl);

        // 3. Store in standard driver
        $this->driver->set($this->currentKey, $cacheEntry, $ttl + 300);

        // Release SWR lock
        \Fphp\Lock\AtomicLock::release('swr:' . $this->currentKey);

        if (!headers_sent()) {
            header('Access-Control-Expose-Headers: X-FPHP-Cache, X-FPHP-Time, X-FPHP-Speedup, X-FPHP-Generation-Time, ETag');
            header('X-FPHP-Cache: MISS (Cached for future requests)');
            header('X-FPHP-Generation-Time: ' . round($elapsedUs / 1000, 3) . 'ms');
            if ($this->config->get('page_cache.etag_enabled', true)) {
                header('ETag: ' . $etag);
            }
        }

        return $processed;
    }

    /**
     * Intercept RFC HTTP PURGE / BAN requests from CDNs, proxies, or webhooks.
     *
     * @return bool
     */
    public function handlePurgeRequest(): bool
    {
        if (!$this->config->get('page_cache.purge_enabled', true)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'HTTP PURGE is disabled']);
            exit;
        }

        // Validate optional secret purge key
        $expectedKey = $this->config->get('page_cache.purge_key');
        if ($expectedKey !== null) {
            $providedKey = $_SERVER['HTTP_X_PURGE_KEY'] ?? $_GET['purge_key'] ?? '';
            if (!hash_equals((string)$expectedKey, (string)$providedKey)) {
                http_response_code(401);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['error' => 'Unauthorized PURGE request']);
                exit;
            }
        }

        $purgeAll = ($_SERVER['HTTP_X_PURGE_ALL'] ?? '') === '1' || isset($_GET['purge_all']);
        if ($purgeAll) {
            $this->bytecodeStore->clear();
            $this->streamStore->clear();
            $this->driver->clear();
            http_response_code(200);
            header('Content-Type: application/json; charset=UTF-8');
            header('X-FPHP-Cache: PURGED');
            echo json_encode([
                'status' => 'success',
                'action' => 'purged_all',
                'timestamp' => time()
            ]);
            exit;
        }

        $key = $this->ruleEngine->generateCacheKey();
        $this->bytecodeStore->delete($key);
        $this->streamStore->delete($key);
        $this->driver->delete($key);

        http_response_code(200);
        header('Content-Type: application/json; charset=UTF-8');
        header('X-FPHP-Cache: PURGED');
        echo json_encode([
            'status' => 'success',
            'action' => 'purged',
            'key' => $key,
            'uri' => $_SERVER['REQUEST_URI'] ?? '/',
            'timestamp' => time()
        ]);
        exit;
    }

    /**
     * Retrieve stale cached entry from either StreamStore or Driver.
     */
    public function retrieveStaleEntry(string $key): ?array
    {
        $staleBytecode = $this->bytecodeStore->retrieveStale($key);
        if ($staleBytecode !== null && !empty($staleBytecode['content_raw'])) {
            return $staleBytecode;
        }

        $staleStream = $this->streamStore->retrieveStale($key);
        if ($staleStream !== null && !empty($staleStream['content_raw'])) {
            return $staleStream;
        }

        $cached = $this->driver->get($key);
        if (is_array($cached) && !empty($cached['content_raw'])) {
            return [
                'content_raw' => $cached['content_raw'],
                'content_type' => $cached['content_type'] ?? 'text/html; charset=UTF-8',
                'etag' => $cached['etag'] ?? '',
                'meta' => $cached
            ];
        }

        return null;
    }

    public function getBytecodeStore(): BytecodeStore
    {
        return $this->bytecodeStore;
    }

    public function getMarkovWarmer(): ?\Fphp\Warmer\MarkovWarmer
    {
        return $this->markovWarmer;
    }

    /**
     * Serve stale cache content when backend encounters 5xx error (RFC 5861 stale-if-error).
     */
    public function serveStaleIfError(int $statusCode = 500): ?string
    {
        if (empty($this->currentKey)) {
            return null;
        }

        $stale = $this->retrieveStaleEntry($this->currentKey);
        if ($stale === null || empty($stale['content_raw'])) {
            return null;
        }

        if (!headers_sent()) {
            http_response_code(200);
            header('X-FPHP-Cache: HIT (Stale-If-Error Disaster Recovery)');
            header('Warning: 110 "Response is Stale"');
            header('X-FPHP-Origin-Status: ' . $statusCode);
            if (!empty($stale['content_type'])) {
                header('Content-Type: ' . $stale['content_type']);
            }
        }

        return $stale['content_raw'];
    }
}
