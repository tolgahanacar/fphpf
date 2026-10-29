<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/fphp-bootstrap.php';

use Fphp\FphpCache;
use Fphp\Driver\FileDriver;
use Fphp\Driver\MemoryDriver;
use Fphp\Driver\ApcuDriver;
use Fphp\Driver\HybridDriver;
use Fphp\Optimizer\Minifier;
use Fphp\Profiler\Metrics;

class CacheTest
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        echo "\n============================================\n";
        echo "   FPHP Cache - Unit & Performance Tests\n";
        echo "============================================\n\n";

        $this->testMemoryDriver();
        $this->testFileDriver();
        $this->testHybridDriver();
        $this->testMinifier();
        $this->testMetrics();
        $this->testTaggedCache();
        $this->testRememberPattern();
        $this->testAtomicLock();
        $this->testAssetOptimizer();
        $this->testOpcacheManager();
        $this->testCacheWarmer();
        $this->testSitemapWarmer();
        $this->testStreamStore();
        $this->testPsr16Adapter();
        $this->testPsr6Adapter();
        $this->testRedisDriver();
        $this->testPrune();
        $this->testFragmentCache();
        $this->testMemoryDriverLRU();
        $this->testXFetchAlgorithm();
        $this->testHostSanitization();
        $this->testStaleIfError();
        $this->testStreamStoreGzip();
        $this->testAtomicLockSpinlock();
        $this->testIfModifiedSince();
        $this->testDeletePrefix();
        $this->testCompressionNegotiation();

        echo "\n--------------------------------------------\n";
        echo "Results: {$this->passed} PASSED, {$this->failed} FAILED\n";
        echo "============================================\n\n";

        if ($this->failed > 0) {
            exit(1);
        }
    }

    private function assert(string $name, bool $condition): void
    {
        if ($condition) {
            echo " [PASS] $name\n";
            $this->passed++;
        } else {
            echo " [FAIL] $name\n";
            $this->failed++;
        }
    }

    private function testMemoryDriver(): void
    {
        echo "Testing MemoryDriver...\n";
        $driver = new MemoryDriver();
        $driver->set('test_key', 'hello_memory', 3600);

        $this->assert('MemoryDriver::get returns correct value', $driver->get('test_key') === 'hello_memory');
        $this->assert('MemoryDriver::has returns true', $driver->has('test_key'));
        
        $driver->delete('test_key');
        $this->assert('MemoryDriver::delete removes key', $driver->get('test_key') === null);
    }

    private function testFileDriver(): void
    {
        echo "\nTesting FileDriver...\n";
        $testDir = dirname(__DIR__) . '/cache/test_file_cache';
        $driver = new FileDriver($testDir, 'test:', true);

        $driver->set('user_123', ['id' => 123, 'name' => 'Alice'], 3600);
        $cached = $driver->get('user_123');

        $this->assert('FileDriver stores & retrieves array', is_array($cached) && $cached['name'] === 'Alice');
        $this->assert('FileDriver::has works', $driver->has('user_123'));

        // Test expiration
        $driver->set('temp_key', 'expires_soon', 1);
        $this->assert('FileDriver before expiration exists', $driver->has('temp_key'));
        sleep(2);
        $this->assert('FileDriver after expiration returns null', $driver->get('temp_key') === null);

        $driver->clear();
        $this->assert('FileDriver::clear flushes directory', $driver->get('user_123') === null);

        // Cleanup
        @rmdir($testDir);
    }

    private function testHybridDriver(): void
    {
        echo "\nTesting HybridDriver...\n";
        $driver = new HybridDriver(dirname(__DIR__) . '/cache', 'hybrid_test:');

        $driver->set('product_99', ['title' => 'MacBook Pro', 'price' => 1999], 3600);
        $val = $driver->get('product_99');

        $this->assert('HybridDriver stores and retrieves complex data', is_array($val) && $val['price'] === 1999);
        $this->assert('HybridDriver stats report available', $driver->getStats()['available'] === true);

        $driver->delete('product_99');
        $this->assert('HybridDriver deletes key across layers', $driver->get('product_99') === null);
    }

    private function testMinifier(): void
    {
        echo "\nTesting Minifier...\n";
        $dirtyHtml = <<<HTML
        <!DOCTYPE html>
        <html>
            <!-- This is a comment to remove -->
            <head>
                <title>Test   Page</title>
                <style>
                    body {
                        background-color: #ffffff ;
                        margin: 0px ;
                    }
                </style>
            </head>
            <body>
                <h1>  Hello   World  </h1>
                <pre>
                    Keep    this   whitespace!
                </pre>
            </body>
        </html>
HTML;

        $minified = Minifier::html($dirtyHtml);

        $this->assert('Minifier removes HTML comments', !str_contains($minified, 'This is a comment to remove'));
        $this->assert('Minifier compresses inline CSS', str_contains($minified, 'background-color:#ffffff;margin:0'));
        $this->assert('Minifier preserves <pre> block spacing', str_contains($minified, 'Keep    this   whitespace!'));
    }

    private function testMetrics(): void
    {
        echo "\nTesting Metrics Profiler...\n";
        Metrics::start('benchmark_test');
        usleep(6000); // 6ms = 6000µs
        $us = Metrics::stopMicroseconds('benchmark_test');

        $this->assert('Metrics measures microsecond elapsed time (> 2500 µs)', $us >= 2500);
        $this->assert('Metrics format string outputs correct unit', str_contains(Metrics::formatTime($us), 'ms'));
    }

    private function testTaggedCache(): void
    {
        echo "\nTesting Tagged Cache...\n";
        FphpCache::init(['driver' => 'file']);

        FphpCache::tag(['category_hardware', 'type_compute'])->set('item_node1', 'Compute Node 01', 3600);
        $this->assert('TaggedCache retrieves item', FphpCache::tag(['category_hardware', 'type_compute'])->get('item_node1') === 'Compute Node 01');

        // Flush one tag
        FphpCache::tag('category_hardware')->flush();
        $this->assert('TaggedCache invalidates when tag is flushed', FphpCache::tag(['category_hardware', 'type_compute'])->get('item_node1') === null);
    }

    private function testRememberPattern(): void
    {
        echo "\nTesting FphpCache::remember()...\n";
        $callCount = 0;
        $producer = function () use (&$callCount) {
            $callCount++;
            return 'heavy_calculated_value';
        };

        FphpCache::delete('heavy_call');

        $val1 = FphpCache::remember('heavy_call', 60, $producer);
        $val2 = FphpCache::remember('heavy_call', 60, $producer);

        $this->assert('FphpCache::remember returns computed value', $val1 === 'heavy_calculated_value');
        $this->assert('FphpCache::remember only executes callback once (cache HIT)', $callCount === 1 && $val2 === 'heavy_calculated_value');

        FphpCache::delete('heavy_call');
    }

    private function testAtomicLock(): void
    {
        echo "\nTesting AtomicLock (Stampede Protection)...\n";
        $lockName = 'test_stampede_lock';
        \Fphp\Lock\AtomicLock::release($lockName);

        $acquired1 = \Fphp\Lock\AtomicLock::acquire($lockName, 5);
        $this->assert('AtomicLock first acquisition succeeds', $acquired1 === true);

        $acquired2 = \Fphp\Lock\AtomicLock::acquire($lockName, 5);
        $this->assert('AtomicLock second acquisition is blocked (Stampede prevented)', $acquired2 === false);

        \Fphp\Lock\AtomicLock::release($lockName);
        $this->assert('AtomicLock released properly', \Fphp\Lock\AtomicLock::isLocked($lockName) === false);
    }

    private function testAssetOptimizer(): void
    {
        echo "\nTesting AssetOptimizer (Early Hints & Preload)...\n";
        $html = '<html><head><link rel="stylesheet" href="/assets/style.css"></head><body><p>Test</p></body></html>';
        $links = \Fphp\Optimizer\AssetOptimizer::extractPreloadLinks($html);
        $this->assert('AssetOptimizer extracts CSS preload link', count($links) > 0 && str_contains($links[0], 'style.css'));

        $injected = \Fphp\Optimizer\AssetOptimizer::injectPreloadTags($html, $links);
        $this->assert('AssetOptimizer injects <link rel="preload"> in <head>', str_contains($injected, '<link rel="preload"'));
    }

    private function testOpcacheManager(): void
    {
        echo "\nTesting OpcacheManager & Preloader...\n";
        $diag = \Fphp\Optimizer\OpcacheManager::getDiagnostics();
        $this->assert('OpcacheManager returns diagnostics array', is_array($diag) && isset($diag['opcache_enabled']));

        $preloadFile = dirname(__DIR__) . '/cache/test_preload.php';
        $gen = \Fphp\Optimizer\OpcacheManager::generatePreloadFile($preloadFile);
        $this->assert('OpcacheManager generates preload.php successfully', $gen === true && file_exists($preloadFile));
        @unlink($preloadFile);
    }

    private function testCacheWarmer(): void
    {
        echo "\nTesting CacheWarmer...\n";
        $warmer = new \Fphp\Warmer\CacheWarmer(dirname(__DIR__) . '/cache');
        $warmer->addUrl('http://localhost/fphpf/public/index.php');
        $urls = $warmer->getUrls();
        $this->assert('CacheWarmer maintains and records warmup URLs', in_array('http://localhost/fphpf/public/index.php', $urls, true));
    }

    private function testStreamStore(): void
    {
        echo "\nTesting Zero-Serialization StreamStore...\n";
        $store = new \Fphp\FullPage\StreamStore(dirname(__DIR__) . '/cache/test_stream');
        $testKey = 'test_page_key';
        $testHtml = '<html><body><h1>Streamed Content</h1></body></html>';
        
        $saved = $store->store($testKey, $testHtml, 'text/html; charset=UTF-8', '"etag_123"', 3600);
        $this->assert('StreamStore stores HTML with ASCII header', $saved === true);

        $filePath = $store->getFilePath($testKey);
        $this->assert('StreamStore creates sharded .fpc file', file_exists($filePath));

        $deleted = $store->delete($testKey);
        $this->assert('StreamStore deletes cache key', $deleted === true && !file_exists($filePath));

        $store->clear();
    }

    private function testSitemapWarmer(): void
    {
        echo "\nTesting Sitemap XML Warmer...\n";
        $warmer = new \Fphp\Warmer\CacheWarmer(dirname(__DIR__) . '/cache');
        
        $sampleXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url><loc>https://example.com/page-1</loc></url>
    <url><loc>https://example.com/page-2</loc></url>
    <url><loc>https://example.com/page-3</loc></url>
</urlset>
XML;

        $parsed = $warmer->parseSitemap($sampleXml);
        $this->assert('CacheWarmer::parseSitemap extracts all URLs', count($parsed) === 3 && in_array('https://example.com/page-2', $parsed, true));

        // Test with local sitemap.xml
        $sitemapFile = dirname(__DIR__) . '/public/sitemap.xml';
        $fetched = $warmer->fetchSitemap($sitemapFile);
        $this->assert('CacheWarmer::fetchSitemap reads local public/sitemap.xml', $fetched !== false && str_contains($fetched, '<urlset'));
    }

    private function testPsr16Adapter(): void
    {
        echo "\nTesting PSR-16 SimpleCache Adapter...\n";
        $psr16 = FphpCache::psr16();

        $psr16->set('psr16_user', ['name' => 'Alice'], 3600);
        $this->assert('PSR-16 set() and get() work', $psr16->get('psr16_user')['name'] === 'Alice');
        $this->assert('PSR-16 has() returns true', $psr16->has('psr16_user'));

        // Test getMultiple & setMultiple
        $psr16->setMultiple(['item_a' => 10, 'item_b' => 20], 3600);
        $mult = $psr16->getMultiple(['item_a', 'item_b', 'item_missing'], 0);
        $this->assert('PSR-16 getMultiple retrieves correctly', $mult['item_a'] === 10 && $mult['item_b'] === 20 && $mult['item_missing'] === 0);

        // Test key validation exception
        $threw = false;
        try {
            $psr16->get('invalid{key}');
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            $threw = true;
        }
        $this->assert('PSR-16 throws InvalidArgumentException on reserved key characters', $threw);

        $psr16->deleteMultiple(['item_a', 'item_b', 'psr16_user']);
    }

    private function testPsr6Adapter(): void
    {
        echo "\nTesting PSR-6 CacheItemPool Adapter...\n";
        $pool = FphpCache::psr6();

        $item = $pool->getItem('psr6_product');
        $this->assert('PSR-6 initial getItem returns miss', !$item->isHit());

        $item->set('SuperComputer');
        $item->expiresAfter(3600);
        $pool->save($item);

        $savedItem = $pool->getItem('psr6_product');
        $this->assert('PSR-6 save and retrieve works', $savedItem->isHit() && $savedItem->get() === 'SuperComputer');

        // Test deferred commit
        $defItem = $pool->getItem('psr6_deferred');
        $defItem->set('DeferredValue');
        $pool->saveDeferred($defItem);
        $pool->commit();

        $this->assert('PSR-6 commit saves deferred items', $pool->hasItem('psr6_deferred'));

        $pool->deleteItem('psr6_product');
        $pool->deleteItem('psr6_deferred');
    }

    private function testRedisDriver(): void
    {
        echo "\nTesting Redis Cluster / Standalone Driver...\n";
        $redis = new \Fphp\Driver\RedisDriver('127.0.0.1', 6379, null, 0, 0.2);

        $this->assert('RedisDriver getName is "redis"', $redis->getName() === 'redis');
        $stats = $redis->getStats();
        $this->assert('RedisDriver getStats reports array with availability status', is_array($stats) && isset($stats['status']));

        // If Redis is not running locally, driver gracefully returns defaults without throwing fatal errors
        if (!$redis->isAvailable()) {
            $this->assert('RedisDriver handles offline server gracefully without fatal error', $redis->get('test_key', 'fallback') === 'fallback');
        } else {
            $redis->set('test_redis', 'val', 60);
            $this->assert('RedisDriver stores and retrieves value', $redis->get('test_redis') === 'val');
            $redis->delete('test_redis');
        }
    }

    private function testPrune(): void
    {
        echo "\nTesting Prune (Garbage Collector)...\n";
        $driver = new \Fphp\Driver\FileDriver(dirname(__DIR__) . '/cache/test_prune');
        // Store an item expired 1 second ago
        $driver->set('expired_key', 'old_data', 1);
        $driver->set('fresh_key', 'valid_data', 3600);
        sleep(2);

        $res = $driver->prune();
        $this->assert('FileDriver::prune deletes expired item', $res['deleted_files'] >= 1);
        $this->assert('FileDriver fresh item still exists', $driver->has('fresh_key'));

        $driver->clear();
        @rmdir(dirname(__DIR__) . '/cache/test_prune');

        // Test FphpCache::prune()
        $globalPrune = FphpCache::prune();
        $this->assert('FphpCache::prune returns summary array', is_array($globalPrune) && isset($globalPrune['total_deleted']));
    }

    private function testFragmentCache(): void
    {
        echo "\nTesting Fragment Caching...\n";
        // Test closure fragment
        $count = 0;
        $html1 = FphpCache::fragment('test_widget', 3600, function() use (&$count) {
            $count++;
            return '<div>Widget Content</div>';
        });

        $html2 = FphpCache::fragment('test_widget', 3600, function() use (&$count) {
            $count++;
            return '<div>Should Not Run</div>';
        });

        $this->assert('FphpCache::fragment caches HTML output', $html1 === '<div>Widget Content</div>' && $html2 === '<div>Widget Content</div>');
        $this->assert('FphpCache::fragment only runs closure once (Cache HIT)', $count === 1);

        // Test startFragment and endFragment buffer
        ob_start();
        if (FphpCache::startFragment('test_buffer_frag', 3600)) {
            echo "<span>Buffer Content</span>";
            FphpCache::endFragment();
        }
        $bufferOutput = ob_get_clean();
        $this->assert('FphpCache startFragment/endFragment buffers and outputs content', str_contains($bufferOutput, 'Buffer Content'));

        // Second call should output cached content directly without entering if block
        ob_start();
        $enteredBlock = false;
        if (FphpCache::startFragment('test_buffer_frag', 3600)) {
            $enteredBlock = true;
            FphpCache::endFragment();
        }
        $cachedOutput = ob_get_clean();
        $this->assert('FphpCache startFragment skips block and outputs cached HTML', !$enteredBlock && str_contains($cachedOutput, 'Buffer Content'));

        FphpCache::delete('fragment:test_widget');
        FphpCache::delete('fragment:test_buffer_frag');
    }

    private function testMemoryDriverLRU(): void
    {
        echo "\nTesting MemoryDriver O(1) LRU Eviction...\n";
        $driver = new MemoryDriver(3);
        $driver->set('k1', 'val1');
        $driver->set('k2', 'val2');
        $driver->set('k3', 'val3');

        // Access k1, so k2 becomes the oldest
        $driver->get('k1');

        // Insert k4, should evict k2
        $driver->set('k4', 'val4');

        $this->assert('LRU kept accessed item k1', $driver->has('k1'));
        $this->assert('LRU evicted unaccessed item k2', !$driver->has('k2'));
        $this->assert('LRU kept items k3 and k4', $driver->has('k3') && $driver->has('k4'));
    }

    private function testXFetchAlgorithm(): void
    {
        echo "\nTesting XFetch (Probabilistic Early Expiration)...\n";
        $runCount = 0;
        $val1 = FphpCache::rememberXFetch('xfetch_test_key', 3600, function() use (&$runCount) {
            $runCount++;
            return 'computed_xfetch_data';
        });

        $this->assert('rememberXFetch cold miss computes value', $val1 === 'computed_xfetch_data');
        $this->assert('rememberXFetch executed closure once', $runCount === 1);

        // Immediate subsequent call should return cached value without running closure
        $val2 = FphpCache::rememberXFetch('xfetch_test_key', 3600, function() use (&$runCount) {
            $runCount++;
            return 'should_not_run';
        });

        $this->assert('rememberXFetch returns cached value (HIT)', $val2 === 'computed_xfetch_data');
        $this->assert('rememberXFetch did not re-run closure', $runCount === 1);

        // Test TaggedCache rememberXFetch
        $tagCache = FphpCache::tag(['products']);
        $tagRunCount = 0;
        $tagVal1 = $tagCache->rememberXFetch('tagged_xfetch_key', 3600, function() use (&$tagRunCount) {
            $tagRunCount++;
            return ['id' => 99, 'name' => 'Widget'];
        });

        $this->assert('TaggedCache::rememberXFetch computes value', is_array($tagVal1) && $tagVal1['id'] === 99);
        $this->assert('TaggedCache::rememberXFetch ran closure once', $tagRunCount === 1);

        $tagVal2 = $tagCache->rememberXFetch('tagged_xfetch_key', 3600, function() use (&$tagRunCount) {
            $tagRunCount++;
            return ['id' => 99, 'name' => 'Changed'];
        });

        $this->assert('TaggedCache::rememberXFetch returns cached value', is_array($tagVal2) && $tagVal2['name'] === 'Widget');
        $this->assert('TaggedCache::rememberXFetch did not re-run closure', $tagRunCount === 1);

        FphpCache::delete('xfetch_test_key');
        FphpCache::delete('tagged_xfetch_key');
    }

    private function testHostSanitization(): void
    {
        echo "\nTesting Host Header Sanitization & Poisoning Defense...\n";
        $ruleEngine = new \Fphp\FullPage\RuleEngine(new \Fphp\Config());

        $sanitized1 = $ruleEngine->sanitizeHost('example.com:8080');
        $this->assert('RuleEngine strips port from Host header', $sanitized1 === 'example.com');

        $sanitized2 = $ruleEngine->sanitizeHost('evil.com<script>alert(1)</script>');
        $this->assert('RuleEngine strips dangerous characters from Host', $sanitized2 === 'evil.comscriptalert1script');

        // Test with trusted_hosts
        $trustedConfig = new \Fphp\Config([
            'page_cache' => [
                'trusted_hosts' => ['app.mysite.com', 'api.mysite.com']
            ]
        ]);
        $ruleEngineTrusted = new \Fphp\FullPage\RuleEngine($trustedConfig);

        $trustedHit = $ruleEngineTrusted->sanitizeHost('app.mysite.com:443');
        $this->assert('RuleEngine accepts trusted host', $trustedHit === 'app.mysite.com');

        $untrustedForced = $ruleEngineTrusted->sanitizeHost('attacker.evil.com');
        $this->assert('RuleEngine falls back to first trusted host on attacker host', $untrustedForced === 'app.mysite.com');
    }

    private function testStaleIfError(): void
    {
        echo "\nTesting Disaster Recovery (RFC 5861 stale-if-error)...\n";
        $config = new \Fphp\Config([
            'cache_dir' => dirname(__DIR__) . '/cache',
            'page_cache' => ['enabled' => true, 'stale_if_error' => true]
        ]);
        $driver = new MemoryDriver();
        $stats = new \Fphp\Profiler\StatsTracker($config->get('cache_dir'));
        $pageCache = new \Fphp\FullPage\PageCache($config, $driver, $stats);

        // Pre-populate cache key
        $key = 'page:test_disaster';
        $driver->set($key, [
            'content_raw' => '<html><body>Rescued Stale Content</body></html>',
            'content_type' => 'text/html; charset=UTF-8',
            'etag' => 'W/"disaster_123"',
            'driver' => 'memory',
            'created_at' => time() - 3600,
            'expires_at' => time() - 100 // EXPIRED!
        ]);

        $staleEntry = $pageCache->retrieveStaleEntry($key);
        $this->assert('retrieveStaleEntry retrieves content even after expiry', is_array($staleEntry) && str_contains($staleEntry['content_raw'], 'Rescued Stale Content'));

        // Reflection to set currentKey and test serveStaleIfError
        $ref = new \ReflectionClass($pageCache);
        $prop = $ref->getProperty('currentKey');
        $prop->setValue($pageCache, $key);

        $rescued = $pageCache->serveStaleIfError(500);
        $this->assert('serveStaleIfError rescues 500 error and serves stale HTML', $rescued !== null && str_contains($rescued, 'Rescued Stale Content'));
    }

    private function testStreamStoreGzip(): void
    {
        echo "\nTesting StreamStore Pre-Gzip Dual Streaming...\n";
        $testDir = dirname(__DIR__) . '/cache/test_stream_gz';
        $store = new \Fphp\FullPage\StreamStore($testDir);

        $testKey = 'gz_page_test';
        $html = '<html><body><h1>Pre-compressed Zero Copy Test</h1><p>' . str_repeat('Content ', 50) . '</p></body></html>';
        $store->store($testKey, $html, 'text/html; charset=UTF-8', 'W/"gz123"', 3600);

        $rawFile = $store->getFilePath($testKey);
        $gzFile = $rawFile . '.gz';

        $this->assert('StreamStore created raw .fpc file', file_exists($rawFile));
        $this->assert('StreamStore created pre-compressed .fpc.gz file', file_exists($gzFile));

        if (file_exists($gzFile)) {
            $this->assert('Pre-compressed .fpc.gz is smaller or equal in size to raw HTML', filesize($gzFile) <= strlen($html));
        }

        $stale = $store->retrieveStale($testKey);
        $this->assert('StreamStore retrieveStale reads uncompressed body accurately', $stale !== null && str_contains($stale['content_raw'], 'Pre-compressed Zero Copy Test'));

        $store->delete($testKey);
        $this->assert('StreamStore delete removes raw .fpc file', !file_exists($rawFile));
        $this->assert('StreamStore delete removes .fpc.gz file', !file_exists($gzFile));

        $store->clear();
        @rmdir($testDir);
    }

    private function testAtomicLockSpinlock(): void
    {
        echo "\nTesting AtomicLock Spinlock & Token Ownership...\n";
        $lockName = 'spin_test_lock_' . uniqid();

        // Acquire lock
        $acquired = \Fphp\Lock\AtomicLock::acquire($lockName, 2);
        $this->assert('AtomicLock acquired successfully', $acquired);

        // Spinlock block should fail while held
        $blocked = \Fphp\Lock\AtomicLock::block($lockName, 2, 50, 1000);
        $this->assert('AtomicLock::block returns false while lock is held', !$blocked);

        // Release lock
        \Fphp\Lock\AtomicLock::release($lockName);

        // Now block should succeed immediately
        $blockSucceeded = \Fphp\Lock\AtomicLock::block($lockName, 2, 100, 1000);
        $this->assert('AtomicLock::block succeeds once lock is freed', $blockSucceeded);

        \Fphp\Lock\AtomicLock::release($lockName);
    }

    private function testIfModifiedSince(): void
    {
        echo "\nTesting RFC 9110 If-Modified-Since...\n";
        $timestamp = time() - 3600; // 1 hour ago

        // Exact match
        $_SERVER['HTTP_IF_MODIFIED_SINCE'] = gmdate('D, d M Y H:i:s \G\M\T', $timestamp);
        $matchExact = \Fphp\FullPage\HeaderManager::matchesIfModifiedSince($timestamp);
        $this->assert('HeaderManager matches exact If-Modified-Since', $matchExact);

        // Client has newer version (future date)
        $_SERVER['HTTP_IF_MODIFIED_SINCE'] = gmdate('D, d M Y H:i:s \G\M\T', $timestamp + 60);
        $matchNewer = \Fphp\FullPage\HeaderManager::matchesIfModifiedSince($timestamp);
        $this->assert('HeaderManager returns true when client has newer date', $matchNewer);

        // Resource was modified after client cached it (stale client)
        $_SERVER['HTTP_IF_MODIFIED_SINCE'] = gmdate('D, d M Y H:i:s \G\M\T', $timestamp - 60);
        $matchOlder = \Fphp\FullPage\HeaderManager::matchesIfModifiedSince($timestamp);
        $this->assert('HeaderManager returns false when resource is newer', !$matchOlder);

        unset($_SERVER['HTTP_IF_MODIFIED_SINCE']);
    }

    private function testDeletePrefix(): void
    {
        echo "\nTesting Wildcard Prefix Invalidation (deletePrefix)...\n";

        // Test in MemoryDriver
        $mem = new MemoryDriver();
        $mem->set('user:1:profile', 'alice');
        $mem->set('user:1:settings', 'dark');
        $mem->set('user:2:profile', 'bob');
        $deletedMem = $mem->deletePrefix('user:1:');
        $this->assert('MemoryDriver deleted 2 keys with prefix user:1:', $deletedMem === 2);
        $this->assert('MemoryDriver kept user:2:profile', $mem->has('user:2:profile'));
        $this->assert('MemoryDriver removed user:1:profile', !$mem->has('user:1:profile'));

        // Test in FileDriver
        $testDir = dirname(__DIR__) . '/cache/test_prefix_cache';
        $fileDriver = new FileDriver($testDir, 'pref:');
        $fileDriver->set('cat:books:1', 'book1');
        $fileDriver->set('cat:books:2', 'book2');
        $fileDriver->set('cat:movies:1', 'movie1');

        $deletedFile = $fileDriver->deletePrefix('cat:books:');
        $this->assert('FileDriver deleted 2 keys with prefix cat:books:', $deletedFile === 2);
        $this->assert('FileDriver kept cat:movies:1', $fileDriver->has('cat:movies:1'));
        $this->assert('FileDriver removed cat:books:1', !$fileDriver->has('cat:books:1'));

        $fileDriver->clear();
        @rmdir($testDir);

        // Test via FphpCache
        FphpCache::set('api:v1:posts', ['p1', 'p2']);
        FphpCache::set('api:v1:comments', ['c1']);
        FphpCache::set('api:v2:posts', ['p3']);

        $deletedGlobal = FphpCache::deletePrefix('api:v1:');
        $this->assert('FphpCache::deletePrefix deleted matching items', $deletedGlobal >= 2);
        $this->assert('FphpCache kept api:v2:posts', FphpCache::has('api:v2:posts'));
        $this->assert('FphpCache removed api:v1:posts', !FphpCache::has('api:v1:posts'));

        FphpCache::delete('api:v2:posts');
    }

    private function testCompressionNegotiation(): void
    {
        echo "\nTesting Multi-Codec Compression Handshake...\n";

        $_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip, deflate, br';
        $brotliSupport = \Fphp\Optimizer\Compression::clientSupportsBrotli();
        $gzipSupport = \Fphp\Optimizer\Compression::clientSupportsGzip();
        $this->assert('Compression detects Brotli support', $brotliSupport);
        $this->assert('Compression detects Gzip support', $gzipSupport);

        $_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip, deflate';
        $negotiated = \Fphp\Optimizer\Compression::negotiateEncoding();
        $this->assert('Compression negotiates gzip when br not loaded or supported', $negotiated === 'gzip');

        unset($_SERVER['HTTP_ACCEPT_ENCODING']);
    }
}

$test = new CacheTest();
$test->run();
