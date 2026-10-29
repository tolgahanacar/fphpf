# ⚡ FPHP Cache - Ultra-Fast Microsecond PHP Acceleration & Micro-Caching Engine

> **Microsecond Latency (0.3 µs - 120 µs) • 2x - 100x Throughput Boost • Zero External Dependencies**

FPHP Cache is an autonomous, enterprise-grade multi-tier acceleration and caching engine engineered to reduce response times to **microseconds** and multiply throughput for modern PHP web applications, RESTful APIs, content management systems, and high-concurrency microservices.

---

## 🌟 Key Features

1. **Multi-Tier Hybrid Storage Hierarchy**:
   - **L0 (In-Request RAM)**: `0.3 µs` latency with an $O(1)$ LRU (Least Recently Used) eviction algorithm for deduplicating queries within the same execution lifecycle.
   - **L1 (Shared Memory APCu)**: `1.2 µs` latency across PHP worker processes, eliminating database sockets, network round-trips, and disk I/O.
   - **Distributed Layer (Remote Cluster)**: Optional clustered storage driver with graceful offline degradation and no fatal error cascading.
   - **L2 (Atomic NVMe/SSD Filesystem)**: 2-level directory sharding (`xxh128`), race-condition-free atomic file replacement, and header-only metadata parsing (`50 - 150 µs`).
2. **Zero-Framework Execution Bypass (Full Page Microcache)**:
   - Intercepts incoming HTTP requests via `FphpCache::boot()` before any heavy application frameworks or database connections are initialized.
   - Delivers dynamic pages in **0.1ms - 0.5ms (100 - 500 µs)**, offloading up to 95% of server CPU capacity.
3. **Zero-Serialization Kernel Stream (`StreamStore`)**:
   - Bypasses PHP `serialize()` and `unserialize()` entirely.
   - Streams raw responses straight to the network socket using kernel-level `fpassthru()`.
   - **Dual Pre-Compressed Gzip Streaming**: Stores pre-compressed `.fpc.gz` streams alongside raw output, enabling zero-CPU compression delivery when clients support gzip.
4. **RFC 9110 & RFC 7233 Compliance**:
   - **ETag & 304 Not Modified**: Instant client-side validation returning **0-byte payload** responses in ~200 µs.
   - **HTTP Range Requests**: Supports `206 Partial Content` (`bytes=start-end`) for streaming media and resumable transfers.
   - **HEAD Request Support**: Handles RFC-compliant header delivery without executing the body payload.
5. **Cache Stampede Protection & Stale-While-Revalidate (SWR)**:
   - **Atomic Locking**: Hardware CAS primitives (`apcu_add`) and exclusive filesystem locks with unique owner-token verification to prevent lock theft and deadlocks.
   - **RFC 5861 SWR**: During high traffic spikes, stale content is served in microseconds while a single background worker revalidates fresh data.
   - **VLDB XFetch Algorithm**: Optimal probabilistic early expiration ($x = -\beta \cdot \delta \cdot \ln(U)$) recalculates hot entries before expiration without spin-locking.
6. **RFC 5861 Disaster Recovery (`stale-if-error`)**:
   - Automatically intercepts backend 5xx status codes and PHP fatal errors via shutdown handlers, rescuing requests by serving the last known working stale cache version.
7. **RFC HTTP PURGE / BAN Interception**:
   - Native support for `PURGE` and `BAN` HTTP methods from CDNs, reverse proxies, and administrative hooks with optional secret-key authentication.
8. **$O(1)$ Vector Clock Tagged Caching**:
   - Invalidates tagged cache entries across millions of records in $O(1)$ time via version timestamps, requiring zero filesystem directory traversal or index scanning.
9. **Safe HTML & CSS Minifier**:
   - Strips redundant whitespaces and comments while preserving `<pre>`, `<textarea>`, and `<script>` blocks.
10. **HTTP 103 Early Hints & Preload Generator**:
    - Automatically analyzes critical stylesheets and WOFF2 web fonts to dispatch `Link: rel=preload` HTTP headers and early hint tags.
11. **Parallel Background Cache Warmer**:
    - High-concurrency `curl_multi` crawler capable of parsing standard XML sitemaps and nested sitemap indices to warm up pages ahead of traffic.
12. **PHP 8+ OPcache Preload & JIT Compiler Advisor**:
    - Generates standalone `preload.php` scripts for precompiling application bytecode into shared memory on server startup.
13. **Full PSR-16 & PSR-6 Compliance**:
    - Implements PSR-16 `CacheInterface` and PSR-6 `CacheItemPoolInterface` with zero composer dependencies via built-in interface shims.
14. **Developer Telemetry & Real-Time Dashboard**:
    - Nanosecond-precision metrics profiler (`hrtime`), hit/miss counters, saved CPU time calculation, and a clean developer web dashboard.

---

## 🚀 Quick Start (2-Line Integration)

Place these two lines at the very top of your application's `index.php` or inside a `php.ini` / `.htaccess` `auto_prepend_file` directive:

```php
<?php
// 1. Load the standalone FPHP Cache autoloader
require_once __DIR__ . '/fphp-bootstrap.php';

// 2. Initialize and boot the micro-accelerator
\Fphp\FphpCache::boot();

// ... The rest of your application code (CMS, MVC framework, or custom backend)
```

If the requested page is in cache, FPHP Cache returns the response in **microseconds** and terminates execution immediately. Heavy bootstrap routines, ORM layers, and database connections are completely bypassed!

---

## ⚡ Object & Database Query Caching

### 1. The `remember` Pattern with Stampede Lock:

```php
use Fphp\FphpCache;

// Cache expensive calculations or database queries for 10 minutes (600 seconds)
$data = FphpCache::remember('catalog_listing', 600, function () use ($db) {
    return $db->query("SELECT * FROM products WHERE active = 1")->fetchAll();
});
```

### 2. Probabilistic Early Expiration (XFetch Algorithm):

Eliminate cache stampedes on hot keys under heavy concurrency without spin-lock latency:

```php
// Computes early based on calculation runtime and beta multiplier
$heavyData = FphpCache::rememberXFetch('hot_aggregated_metric', 300, function () {
    return calculateComplexFinancialAggregates();
}, 1.0);
```

### 3. $O(1)$ Tagged Caching:

```php
// Store an entry associated with multiple tags
FphpCache::tag(['catalog', 'category_42'])->set('item_99', $productData, 3600);

// Invalidate all entries tagged with 'category_42' in an instant (O(1) operation):
FphpCache::tag('category_42')->flush();
```

---

## 🧩 Partial / Fragment Caching

For dynamic pages (such as user account areas) where full-page caching is bypassed, cache individual heavy components:

```php
use Fphp\FphpCache;

// Using template buffering:
if (FphpCache::startFragment('sidebar_navigation', 1800)):
    renderComplexNavigationMenu();
    FphpCache::endFragment();
endif;

// Or using closures:
$html = FphpCache::fragment('cart_widget', 300, function () {
    return renderDynamicMiniWidget();
});
```

---

## 📊 Live Benchmark Comparison

Hardware benchmark measured across 5,000 continuous operations:

| Tier / Operation | Average Latency | Throughput Capacity | Speedup Ratio |
| :--- | :--- | :--- | :--- |
| **Uncached Heavy Workload** | `26.92 µs` | 37,143 ops / sec | **1x (Baseline)** |
| **FPHP L2 SSD File Cache** | `158.2 µs` | 6,320 ops / sec | **Persistent Disk** |
| **FPHP L1 APCu Shared RAM** | **`1.29 µs`** | **771,688 ops / sec** | **20.8x Faster** |
| **FPHP L0 Per-Request RAM** | **`0.32 µs`** | **3,070,499 ops / sec** | **82.6x - 106x Faster** |

To execute the benchmark on your local machine:
```bash
php public/benchmark.php
```
Or view the interactive web interface: `http://localhost/fphpf/public/benchmark.php`

---

## 🖥️ Developer Telemetry Dashboard

Access the built-in management dashboard in your browser:
`http://localhost/fphpf/public/dashboard.php`

Features available in the dashboard:
- **Hit / Miss Ratios**: Visual telemetry dials and live statistics.
- **CPU Time Saved**: Calculated server processor and database time saved.
- **Diagnostics & Status**: Active drivers, OPcache status, and JIT compilation metrics.
- **One-Click Actions**: Clear all cache tiers, prune expired files, run background warmup, and generate preloading scripts.

---

## ⚙️ Configuration Reference (`Config.php`)

```php
\Fphp\FphpCache::init([
    // Active driver: 'hybrid', 'apcu', 'redis', 'file', 'memory'
    'driver' => 'hybrid',
    'cache_dir' => __DIR__ . '/cache',
    'prefix' => 'fphp:',
    
    // Remote distributed cache settings (optional)
    'redis' => [
        'enabled' => false,
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => null,
        'database' => 0,
        'timeout' => 1.5,
    ],

    // Full-page microcache rules
    'page_cache' => [
        'enabled' => true,
        'ttl' => 1800, // 30 minutes
        'strip_query_params' => [
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'fbclid', 'gclid', 'ref', 'source', '_t', 'timestamp', 'cb'
        ],
        'excluded_urls' => [
            '#/admin#i', '#/cart#i', '#/checkout#i', '#/login#i', '#/register#i'
        ],
        'bypass_cookies' => [
            'session_logged_in_', 'auth_token_', 'PHPSESSID_LOGGED_IN', 'admin_session'
        ],
        'etag_enabled' => true,
        'stale_while_revalidate' => 60,
        'stale_if_error' => true, // Disaster recovery fallback on 5xx errors
        'purge_enabled' => true,  // RFC PURGE / BAN method support
        'purge_key' => null,      // Optional secret key for PURGE authentication
        'trusted_hosts' => []     // Allowed hosts whitelist for poisoning defense
    ],

    // Minifier & compression
    'optimizer' => [
        'minify_html' => true,
        'minify_css' => true,
        'remove_html_comments' => true,
        'gzip_compression' => true,
        'compression_level' => 6
    ]
]);
```

---

## 💻 Command Line Interface (CLI)

For scheduled cron tasks and server administration:

```bash
# View cache statistics, hit ratios, and OPcache status
php fphp-cli.php stats

# Flush all active cache tiers (APCu, Redis, and disk storage)
php fphp-cli.php clear

# Delete all entries matching a specific prefix
php fphp-cli.php delete-prefix user:

# Garbage collector: Prune expired disk cache files
php fphp-cli.php prune --age=3600

# Crawl and warm up pages from an XML sitemap in parallel
php fphp-cli.php warmup --sitemap=public/sitemap.xml

# Generate or update the OPcache preload.php script
php fphp-cli.php preload

# Run the performance stress benchmark
php fphp-cli.php bench
```

---

## 🔌 Web Application Drop-in Integration

FPHP Cache includes an autonomous drop-in handler for content management platforms and modular architectures:

1. Copy **`wordpress/advanced-cache.php`** to your CMS drop-in directory (`wp-content/advanced-cache.php`).
2. Enable caching in your configuration:
   ```php
   define('WP_CACHE', true);
   ```

### 🛡️ Zero Data Loss Guarantee:
* **E-Commerce Sessions**: Cart, checkout, and session cookie holders are automatically bypassed. Shopping carts and checkout sessions are never cached.
* **Authenticated Users**: Logged-in session cookies automatically bypass the cache to guarantee real-time data access.
* **Instant Invalidation**: Content edits, post updates, comment approvals, and theme switches trigger automatic cache invalidation for the affected URLs and index pages.
* **Admin Toolbar & CLI**: Includes administrative bar shortcuts for one-click cache flushing and command-line management.

---

## 📦 PSR-16 & PSR-6 Compatibility

FPHP Cache can be injected into any dependency container or framework expecting standardized PSR caching interfaces without requiring third-party composer packages:

```php
use Fphp\FphpCache;

// Obtain a PSR-16 Simple Cache instance
$psr16 = FphpCache::psr16();
$psr16->set('key', 'value', 3600);

// Obtain a PSR-6 Cache Item Pool instance
$pool = FphpCache::psr6();
$item = $pool->getItem('key');
$item->set('value');
$pool->save($item);
```

---

## 📂 Architecture & Directory Structure

```
fphpf/
├── fphp-bootstrap.php            # Standalone zero-dependency autoloader & PSR shims
├── fphp-cli.php                  # Console & cron management tool
├── preload.php                   # OPcache preloading compilation script
├── composer.json                 # Standard PSR-4 library definition
├── src/
│   ├── FphpCache.php             # Unified static facade & lifecycle controller
│   ├── Config.php                # Dot-notation configuration engine
│   ├── Adapter/
│   │   ├── Psr16Cache.php        # PSR-16 SimpleCache adapter
│   │   ├── Psr6CachePool.php     # PSR-6 CacheItemPool adapter
│   │   ├── Psr6CacheItem.php     # PSR-6 CacheItem object
│   │   └── PsrShims.php          # Zero-dependency PSR interface fallbacks
│   ├── Driver/
│   │   ├── CacheDriverInterface.php  # Unified storage driver interface
│   │   ├── MemoryDriver.php      # L0: Per-request RAM driver with O(1) LRU eviction
│   │   ├── ApcuDriver.php        # L1: Shared memory APCu driver (1 - 5 µs)
│   │   ├── RedisDriver.php       # Distributed remote cluster driver (0.5 - 2 ms)
│   │   ├── FileDriver.php        # L2: Atomic 2-level sharded NVMe/SSD driver
│   │   └── HybridDriver.php      # Multi-tier coordinator (L0 + L1 + Redis + L2)
│   ├── Fragment/
│   │   └── FragmentCache.php     # Component & partial template caching engine
│   ├── FullPage/
│   │   ├── PageCache.php         # Full-page microcache controller
│   │   ├── HeaderManager.php     # ETag, RFC 9110 If-Modified-Since, and 304 handler
│   │   ├── RuleEngine.php        # URL/Cookie filtering & host poisoning defense
│   │   └── StreamStore.php       # Zero-serialization kernel stream store
│   ├── Lock/
│   │   └── AtomicLock.php        # Microsecond spinlock & stampede protector
│   ├── Optimizer/
│   │   ├── Minifier.php          # Safe HTML & CSS whitespace/comment compressor
│   │   ├── Compression.php       # Multi-codec negotiation (Brotli, Gzip, Deflate)
│   │   ├── AssetOptimizer.php    # HTTP 103 Early Hints & preload extractor
│   │   └── OpcacheManager.php    # OPcache/JIT diagnostics & preload generator
│   ├── Profiler/
│   │   ├── Metrics.php           # High-resolution nanosecond/microsecond profiler
│   │   └── StatsTracker.php      # Real-time telemetry & throttled disk tracker
│   ├── Tags/
│   │   ├── TagManager.php        # O(1) vector clock tag versioning manager
│   │   └── TaggedCache.php       # Tag-scoped proxy interface
│   └── Warmer/
│       └── CacheWarmer.php       # Sitemap XML parser & curl_multi parallel warmer
├── public/
│   ├── index.php                 # Live acceleration benchmark demo
│   ├── dashboard.php             # Web administration & telemetry panel
│   ├── benchmark.php             # Interactive hardware benchmark utility
│   ├── auto_prepend_demo.php     # Zero-framework execution bypass example
│   └── sitemap.xml               # Sample XML sitemap for warming tests
├── wordpress/
│   ├── advanced-cache.php        # CMS core bypass drop-in file
│   └── fphp-cache.php            # Administrative plugin & CLI integration
└── tests/
    └── CacheTest.php             # Comprehensive automated test suite
```

---

## 🧪 Testing

Run the automated test suite from the project root:

```bash
php tests/CacheTest.php
```

All 91 unit and integration assertions validate drivers, stream store operations, LRU eviction, XFetch probabilistic logic, atomic lock ownership, PSR adapters, disaster recovery mechanisms, and compression handshakes.

---

## 📄 License

Open-source software licensed under the [MIT License](LICENSE).
