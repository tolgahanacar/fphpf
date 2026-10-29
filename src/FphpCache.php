<?php

declare(strict_types=1);

namespace Fphp;

use Fphp\Driver\CacheDriverInterface;
use Fphp\Driver\ApcuDriver;
use Fphp\Driver\FileDriver;
use Fphp\Driver\MemoryDriver;
use Fphp\Driver\HybridDriver;
use Fphp\FullPage\PageCache;
use Fphp\Profiler\StatsTracker;
use Fphp\Profiler\Metrics;
use Fphp\Tags\TagManager;
use Fphp\Tags\TaggedCache;

/**
 * FphpCache - High-Performance Microsecond PHP Acceleration Engine
 * 
 * Features:
 * - Microsecond in-memory (APCu) and atomic SSD/NVMe caching
 * - Full-Page Microcache with zero-framework execution bypass (0.1ms responses)
 * - Object, Database Query, and Fragment caching (remember, tag)
 * - Automatic HTML/CSS minifier and ETag / 304 Not Modified handler
 * - Real-time telemetry, hit/miss metrics, and developer dashboard
 */
class FphpCache
{
    public const VERSION = '1.0.0';

    protected static ?self $instance = null;
    protected Config $config;
    protected CacheDriverInterface $driver;
    protected PageCache $pageCache;
    protected StatsTracker $stats;
    protected TagManager $tagManager;
    protected ?\Fphp\Fragment\FragmentCache $fragmentCache = null;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? new Config();
        $this->stats = new StatsTracker($this->config->get('cache_dir'));

        $driverType = $this->config->get('driver', 'hybrid');
        $this->driver = $this->createDriver($driverType);
        $this->tagManager = new TagManager($this->driver);
        $this->pageCache = new PageCache($this->config, $this->driver, $this->stats);
    }

    /**
     * Create the appropriate cache driver based on configuration.
     */
    protected function createDriver(string $type): CacheDriverInterface
    {
        $cacheDir = $this->config->get('cache_dir');
        $prefix = $this->config->get('prefix', 'fphp:');
        $useCompression = (bool)$this->config->get('optimizer.gzip_compression', true);
        $compLevel = (int)$this->config->get('optimizer.compression_level', 6);

        $redisDriver = null;
        if ((bool)$this->config->get('redis.enabled', false)) {
            $redisDriver = new \Fphp\Driver\RedisDriver(
                (string)$this->config->get('redis.host', '127.0.0.1'),
                (int)$this->config->get('redis.port', 6379),
                $this->config->get('redis.password'),
                (int)$this->config->get('redis.database', 0),
                (float)$this->config->get('redis.timeout', 1.5),
                $prefix
            );
        }

        return match ($type) {
            'apcu' => new ApcuDriver($prefix),
            'file' => new FileDriver($cacheDir, $prefix, $useCompression, $compLevel),
            'memory' => new MemoryDriver(),
            'redis' => $redisDriver ?? new \Fphp\Driver\RedisDriver(
                (string)$this->config->get('redis.host', '127.0.0.1'),
                (int)$this->config->get('redis.port', 6379),
                $this->config->get('redis.password'),
                (int)$this->config->get('redis.database', 0),
                (float)$this->config->get('redis.timeout', 1.5),
                $prefix
            ),
            default => new HybridDriver($cacheDir, $prefix, $useCompression, $redisDriver)
        };
    }

    /**
     * Get or initialize the singleton instance.
     */
    public static function instance(?Config $config = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Initialize with custom configuration array.
     */
    public static function init(array $config = []): self
    {
        self::$instance = new self(new Config($config));
        return self::$instance;
    }

    /**
     * Drop-in accelerator bootloader:
     * Call at the very top of index.php or in php.ini auto_prepend_file.
     * If cached, serves page in microseconds and terminates immediately.
     */
    public static function boot(array $config = []): void
    {
        if (self::$instance === null) {
            self::init($config);
        }

        self::$instance->pageCache->serve();
    }

    /**
     * Get an item from cache.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::instance()->driver->get($key, $default);
    }

    /**
     * Store an item in cache.
     */
    public static function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        return self::instance()->driver->set($key, $value, $ttl);
    }

    /**
     * Check if key exists.
     */
    public static function has(string $key): bool
    {
        return self::instance()->driver->has($key);
    }

    /**
     * Get an item or compute and store it if missing.
     * Features enterprise Cache Stampede / Dogpile protection via AtomicLock.
     */
    public static function remember(string $key, int $ttl, callable $callback): mixed
    {
        $val = self::get($key);
        if ($val !== null) {
            return $val;
        }

        $lockKey = 'remember:' . $key;
        $hasLock = \Fphp\Lock\AtomicLock::acquire($lockKey, 8);

        if (!$hasLock) {
            // Another worker is computing this! Spin-wait up to 50ms in 2ms intervals
            for ($i = 0; $i < 25; $i++) {
                usleep(2000);
                $val = self::get($key);
                if ($val !== null) {
                    return $val;
                }
            }
        }

        try {
            $computed = $callback();
            self::set($key, $computed, $ttl);
            return $computed;
        } finally {
            if ($hasLock) {
                \Fphp\Lock\AtomicLock::release($lockKey);
            }
        }
    }

    /**
     * Optimal Probabilistic Early Expiration (XFetch Algorithm).
     * 
     * Based on "Optimal Probabilistic Cache Stampede Prevention" (VLDB):
     * Eliminates cache stampede on hot keys without spin-locking or blocking latency.
     * Computes early based on execution time (delta), random variable (U), and beta multiplier.
     *
     * @param string $key
     * @param int $ttl Lifetime in seconds
     * @param callable $callback Expensive calculation closure
     * @param float $beta Aggressiveness parameter (default: 1.0)
     * @return mixed
     */
    public static function rememberXFetch(string $key, int $ttl, callable $callback, float $beta = 1.0): mixed
    {
        $cached = self::get($key);
        $now = microtime(true);

        if (is_array($cached) && isset($cached['__xfetch'])) {
            $delta = (float)($cached['delta'] ?? 0.05);
            $expiry = (float)($cached['expires_at'] ?? 0);
            $remaining = $expiry - $now;

            // XFetch formula: early threshold x = - beta * delta * ln(U)
            // U is uniformly distributed in (0, 1]
            $u = max(0.000001, (float)mt_rand(1, 1000000) / 1000000.0);
            $x = - $beta * $delta * log($u);

            // If remaining time is greater than probabilistic threshold, return cached value
            if ($remaining > $x) {
                return $cached['val'];
            }

            // Inside early expiration window: try acquiring lock to recompute
            $lockKey = 'xfetch:' . $key;
            $hasLock = \Fphp\Lock\AtomicLock::acquire($lockKey, 5);

            if (!$hasLock) {
                // Another worker is already recomputing! Return stale/current value immediately without blocking!
                return $cached['val'];
            }

            try {
                $t0 = microtime(true);
                $newVal = $callback();
                $execDelta = max(0.001, microtime(true) - $t0);

                $entry = [
                    'val' => $newVal,
                    'delta' => $execDelta,
                    'expires_at' => microtime(true) + $ttl,
                    '__xfetch' => true
                ];

                self::set($key, $entry, $ttl + 300);
                return $newVal;
            } finally {
                \Fphp\Lock\AtomicLock::release($lockKey);
            }
        }

        // Cold miss: Acquire lock and compute
        $lockKey = 'xfetch:' . $key;
        $hasLock = \Fphp\Lock\AtomicLock::acquire($lockKey, 8);

        if (!$hasLock) {
            // Spin-wait up to 50ms in 2ms intervals
            for ($i = 0; $i < 25; $i++) {
                usleep(2000);
                $cached = self::get($key);
                if (is_array($cached) && isset($cached['__xfetch'])) {
                    return $cached['val'];
                }
            }
        }

        try {
            $t0 = microtime(true);
            $computed = $callback();
            $execDelta = max(0.001, microtime(true) - $t0);

            $entry = [
                'val' => $computed,
                'delta' => $execDelta,
                'expires_at' => microtime(true) + $ttl,
                '__xfetch' => true
            ];

            self::set($key, $entry, $ttl + 300);
            return $computed;
        } finally {
            if ($hasLock) {
                \Fphp\Lock\AtomicLock::release($lockKey);
            }
        }
    }

    /**
     * Cache Warmer instance.
     */
    public static function getWarmer(): \Fphp\Warmer\CacheWarmer
    {
        return new \Fphp\Warmer\CacheWarmer(self::instance()->config->get('cache_dir'));
    }

    /**
     * OPcache & JIT Diagnostics.
     */
    public static function getOpcacheDiagnostics(): array
    {
        return \Fphp\Optimizer\OpcacheManager::getDiagnostics();
    }

    /**
     * Generate standalone OPcache preload.php file.
     */
    public static function generatePreloadFile(): bool
    {
        $output = dirname(__DIR__) . '/preload.php';
        return \Fphp\Optimizer\OpcacheManager::generatePreloadFile($output);
    }

    /**
     * Delete an item.
     */
    public static function delete(string $key): bool
    {
        $inst = self::instance();
        (new \Fphp\FullPage\BytecodeStore($inst->config->get('cache_dir')))->delete($key);
        (new \Fphp\FullPage\StreamStore($inst->config->get('cache_dir')))->delete($key);
        return $inst->driver->delete($key);
    }

    /**
     * Clear all cached data across all storage layers.
     */
    public static function clear(): bool
    {
        $inst = self::instance();
        (new \Fphp\FullPage\BytecodeStore($inst->config->get('cache_dir')))->clear();
        (new \Fphp\FullPage\StreamStore($inst->config->get('cache_dir')))->clear();
        return $inst->driver->clear();
    }

    /**
     * Delete all items matching a given key prefix across active cache tiers.
     *
     * @param string $prefix Key prefix to match
     * @return int Number of deleted items
     */
    public static function deletePrefix(string $prefix): int
    {
        $inst = self::instance();
        if (method_exists($inst->driver, 'deletePrefix')) {
            return $inst->driver->deletePrefix($prefix);
        }
        return 0;
    }

    /**
     * Garbage Collector: Prune all expired disk cache files across drivers.
     *
     * @param int $maxAge Optional max age in seconds
     * @return array Summary of pruned files and freed bytes
     */
    public static function prune(int $maxAge = 0): array
    {
        $inst = self::instance();
        $streamStore = new \Fphp\FullPage\StreamStore($inst->config->get('cache_dir'));
        $streamPrune = $streamStore->prune($maxAge);

        $bytecodeStore = new \Fphp\FullPage\BytecodeStore($inst->config->get('cache_dir'));
        $bytecodePrune = $bytecodeStore->prune($maxAge);

        $driverPrune = ['deleted_files' => 0, 'freed_bytes' => 0];
        if (method_exists($inst->driver, 'prune')) {
            $driverPrune = $inst->driver->prune($maxAge);
        }

        return [
            'total_deleted' => ($streamPrune['deleted_files'] ?? 0) + ($bytecodePrune['deleted_files'] ?? 0) + ($driverPrune['deleted_files'] ?? 0),
            'total_freed_bytes' => ($streamPrune['freed_bytes'] ?? 0) + ($bytecodePrune['freed_bytes'] ?? 0) + ($driverPrune['freed_bytes'] ?? 0),
            'bytecode_cache' => $bytecodePrune,
            'stream_cache' => $streamPrune,
            'file_cache' => $driverPrune
        ];
    }

    /**
     * Fragment / Partial caching with closure.
     */
    public static function fragment(string $id, int $ttl, callable $callback): string
    {
        return self::getFragmentEngine()->remember($id, $ttl, $callback);
    }

    /**
     * Start fragment template buffering.
     */
    public static function startFragment(string $id, int $ttl = 3600): bool
    {
        return self::getFragmentEngine()->start($id, $ttl);
    }

    /**
     * End fragment template buffering.
     */
    public static function endFragment(): string
    {
        return self::getFragmentEngine()->end();
    }

    /**
     * Get FragmentCache instance.
     */
    public static function getFragmentEngine(): \Fphp\Fragment\FragmentCache
    {
        $inst = self::instance();
        if ($inst->fragmentCache === null) {
            $inst->fragmentCache = new \Fphp\Fragment\FragmentCache($inst->driver);
        }
        return $inst->fragmentCache;
    }

    /**
     * Tagged caching scope.
     */
    public static function tag(array|string $tags): TaggedCache
    {
        $inst = self::instance();
        return new TaggedCache($inst->driver, $inst->tagManager, (array)$tags);
    }

    /**
     * Get active driver.
     */
    public static function getDriver(): CacheDriverInterface
    {
        return self::instance()->driver;
    }

    /**
     * Get configuration container.
     */
    public static function getConfig(): Config
    {
        return self::instance()->config;
    }

    /**
     * Get telemetry stats.
     */
    public static function getStats(): array
    {
        return self::instance()->stats->getSummary();
    }

    /**
     * Get stats tracker instance.
     */
    public static function getStatsTracker(): StatsTracker
    {
        return self::instance()->stats;
    }

    /**
     * Get PageCache engine.
     */
    public static function getPageCache(): PageCache
    {
        return self::instance()->pageCache;
    }

    /**
     * Get TagManager.
     */
    public static function getTagManager(): TagManager
    {
        return self::instance()->tagManager;
    }

    /**
     * Get PSR-16 (Simple Cache) compliant instance.
     */
    public static function psr16(): \Fphp\Adapter\Psr16Cache
    {
        return new \Fphp\Adapter\Psr16Cache(self::instance()->driver);
    }

    /**
     * Get PSR-6 (CacheItemPool) compliant instance.
     */
    public static function psr6(): \Fphp\Adapter\Psr6CachePool
    {
        return new \Fphp\Adapter\Psr6CachePool(self::instance()->driver);
    }

    /**
     * Declare a dynamic hole / island inside cached markup.
     *
     * @param string $slot
     * @param callable|null $resolver
     * @param string $fallback
     * @return string
     */
    public static function hole(string $slot, ?callable $resolver = null, string $fallback = ''): string
    {
        return \Fphp\Fragment\HolePuncher::hole($slot, $resolver, $fallback);
    }

    /**
     * Register a dynamic hole resolver.
     */
    public static function registerHole(string $slot, callable $resolver): void
    {
        \Fphp\Fragment\HolePuncher::register($slot, $resolver);
    }

    /**
     * Set a static hole value for current request.
     */
    public static function setHoleValue(string $slot, string $value): void
    {
        \Fphp\Fragment\HolePuncher::set($slot, $value);
    }

    /**
     * Get active BytecodeStore instance.
     */
    public static function getBytecodeStore(): \Fphp\FullPage\BytecodeStore
    {
        return self::instance()->pageCache->getBytecodeStore();
    }

    /**
     * Get active MarkovWarmer instance.
     */
    public static function getMarkovWarmer(): ?\Fphp\Warmer\MarkovWarmer
    {
        return self::instance()->pageCache->getMarkovWarmer();
    }

    /**
     * Generate web server sidecar configuration snippet for kernel bypass.
     *
     * @param string $server 'nginx' or 'caddy'
     * @return string Configuration block
     */
    public static function sidecar(string $server = 'nginx'): string
    {
        $dir = self::instance()->config->get('cache_dir');
        return match (strtolower($server)) {
            'caddy' => \Fphp\Optimizer\ServerConfigGenerator::caddy($dir),
            default => \Fphp\Optimizer\ServerConfigGenerator::nginx($dir)
        };
    }

    /**
     * Get active SpeculationRulesBridge instance.
     */
    public static function getSpeculationBridge(): ?\Fphp\Warmer\SpeculationRulesBridge
    {
        return self::instance()->pageCache->getSpeculationBridge();
    }

    /**
     * Generate W3C Speculation Rules array for the specified or current URL.
     */
    public static function speculationRules(?string $url = null): array
    {
        $bridge = self::getSpeculationBridge();
        if ($bridge === null) {
            return [];
        }
        $targetUrl = $url ?? ($_SERVER['REQUEST_URI'] ?? '/');
        return $bridge->generateRules($targetUrl);
    }

    /**
     * Render <script type="speculationrules"> tag for the specified or current URL.
     */
    public static function renderSpeculationTag(?string $url = null): string
    {
        $bridge = self::getSpeculationBridge();
        if ($bridge === null) {
            return '';
        }
        $targetUrl = $url ?? ($_SERVER['REQUEST_URI'] ?? '/');
        return $bridge->renderTag($targetUrl);
    }

    /**
     * Inject W3C Speculation Rules script tag into HTML markup.
     */
    public static function injectSpeculationRules(string $html, ?string $url = null): string
    {
        $bridge = self::getSpeculationBridge();
        if ($bridge === null) {
            return $html;
        }
        $targetUrl = $url ?? ($_SERVER['REQUEST_URI'] ?? '/');
        return $bridge->inject($html, $targetUrl);
    }

    /**
     * Get active Reactive LineageGraph instance.
     */
    public static function getLineageGraph(): ?\Fphp\Reactive\LineageGraph
    {
        return self::instance()->pageCache->getLineageGraph();
    }

    /**
     * Explicitly record a data lineage dependency for a cache artifact.
     *
     * @param string|array $entities e.g. 'users:42', ['table:products', 'category:hardware']
     * @param string|null $artifactKey Cache key or null for active context
     * @param string $type
     */
    public static function recordLineage(string|array $entities, ?string $artifactKey = null, string $type = 'page'): void
    {
        $graph = self::getLineageGraph();
        if ($graph === null) {
            return;
        }

        if ($artifactKey !== null) {
            $graph->record($entities, $artifactKey, $type);
        } else {
            // Register into active context stack
            $entityList = is_array($entities) ? $entities : [$entities];
            foreach ($entityList as $e) {
                $graph->record($e, 'pending_context', $type);
            }
        }
    }

    /**
     * Signal that one or more data entities have mutated.
     * Atomically traces all dependent artifacts in the DAG and invalidates them in O(1).
     *
     * Supports exact keys ('users:42') and wildcards ('table:products:*').
     *
     * @param string|array $entities
     * @return array Invalidation report ['evicted_count' => int, 'evicted_keys' => string[]]
     */
    public static function mutate(string|array $entities): array
    {
        $graph = self::getLineageGraph();
        if ($graph === null) {
            return ['evicted_count' => 0, 'evicted_keys' => [], 'matched_entities' => []];
        }
        return $graph->mutate($entities);
    }

    /**
     * Execute a block of code within a reactive data lineage context.
     * Any cache entry created inside will automatically register these entities.
     *
     * @param string|array $entities
     * @param callable $callback
     * @return mixed
     */
    public static function trackLineage(string|array $entities, callable $callback): mixed
    {
        return \Fphp\Reactive\LineageGraph::track($entities, $callback);
    }

    /**
     * Get active Cybernetic PID Controller instance.
     */
    public static function getPidController(): ?\Fphp\Adaptive\PidController
    {
        return self::instance()->pageCache->getPidController();
    }

    /**
     * Dynamically adapt Stale-While-Revalidate window based on live system pressure.
     */
    public static function adaptSwr(int $baseSeconds = 60): int
    {
        $pid = self::getPidController();
        return $pid !== null ? $pid->adaptStaleWhileRevalidate($baseSeconds) : $baseSeconds;
    }

    /**
     * Dynamically adapt compression level based on live system memory pressure.
     */
    public static function adaptCompression(int $baseLevel = 6): int
    {
        $pid = self::getPidController();
        return $pid !== null ? $pid->adaptCompressionLevel($baseLevel) : $baseLevel;
    }

    /**
     * Retrieve real-time PID homeostasis diagnostic telemetry.
     */
    public static function pidTelemetry(): array
    {
        $pid = self::getPidController();
        return $pid !== null ? $pid->getTelemetry() : [];
    }
}
