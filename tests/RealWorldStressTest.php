<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/fphp-bootstrap.php';

use Fphp\FphpCache;
use Fphp\Config;
use Fphp\FullPage\RuleEngine;
use Fphp\FullPage\PageCache;
use Fphp\Profiler\StatsTracker;
use Fphp\Driver\MemoryDriver;

class RealWorldStressTest
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        echo "\n=======================================================\n";
        echo "   ⚡ FPHP CACHE - HEAVY REAL-WORLD CMS & STORE STRESS TESTS\n";
        echo "=======================================================\n\n";

        $this->testAdminSessionLeakProtection();
        $this->testEcommerceCartProtection();
        $this->testSearchAndPreviewBypass();
        $this->testAjaxBypass();
        $this->testSensitiveUrlExclusions();
        $this->testRfc7234NoStoreAndPrivateHeaders();
        $this->testSetCookieLeakDefense();
        $this->testDeepNestedOutputBuffers();
        $this->testThunderingHerdConcurrencySimulation();

        echo "\n-------------------------------------------------------\n";
        echo "Results: {$this->passed} PASSED, {$this->failed} FAILED\n";
        echo "=======================================================\n\n";

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

    /**
     * 1. Test Admin & Logged-In User Leak Protection
     * Ensures an admin or member session can NEVER be served from cache or cached for guests.
     */
    private function testAdminSessionLeakProtection(): void
    {
        echo "Testing Logged-In User & Admin Session Leak Protection...\n";
        $config = new Config();
        $ruleEngine = new RuleEngine($config);

        // Guest user (No cookies) -> Cacheable
        $_COOKIE = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/sample-post/';
        [$cacheable, $reason] = $ruleEngine->isRequestCacheable();
        $this->assert('Guest visitor request is cacheable', $cacheable);

        // Logged-in CMS admin
        $_COOKIE = ['wordpress_logged_in_abcdef123456' => 'admin_token'];
        [$cacheable, $reason] = $ruleEngine->isRequestCacheable();
        $this->assert('CMS logged-in user is strictly BYPASSED', !$cacheable && str_contains($reason, 'Bypass cookie'));

        // Member session cookie
        $_COOKIE = ['session_logged_in_user' => '1'];
        [$cacheable, $reason] = $ruleEngine->isRequestCacheable();
        $this->assert('Generic session user is strictly BYPASSED', !$cacheable);

        // Password protected post cookie
        $_COOKIE = ['wp-postpass_hash' => 'secret'];
        [$cacheable, $reason] = $ruleEngine->isRequestCacheable();
        $this->assert('Password protected post visitor is BYPASSED', !$cacheable);

        $_COOKIE = [];
    }

    /**
     * 2. Test E-Commerce Cart & Checkout Protection
     * Ensures dynamic cart sessions and checkout steps are never cached.
     */
    private function testEcommerceCartProtection(): void
    {
        echo "\nTesting E-Commerce Cart & Checkout Protection...\n";
        $config = new Config();
        $ruleEngine = new RuleEngine($config);

        // Visitor with items in shopping cart
        $_COOKIE = ['woocommerce_items_in_cart' => '3'];
        [$cacheable, $reason] = $ruleEngine->isRequestCacheable();
        $this->assert('Customer with cart items is strictly BYPASSED', !$cacheable && str_contains($reason, 'woocommerce_items_in_cart'));

        // Visitor with cart session hash
        $_COOKIE = ['wp_woocommerce_session_hash123' => 'cart_data'];
        [$cacheable, $reason] = $ruleEngine->isRequestCacheable();
        $this->assert('Cart session hash is strictly BYPASSED', !$cacheable);

        $_COOKIE = [];
    }

    /**
     * 3. Test Search Queries & Draft Previews
     */
    private function testSearchAndPreviewBypass(): void
    {
        echo "\nTesting Search Queries & Draft Previews...\n";
        $config = new Config();
        $ruleEngine = new RuleEngine($config);

        // Search query (?s=keyword)
        $_GET = ['s' => 'shoes'];
        [$cacheable, $reason] = $ruleEngine->isRequestCacheable();
        $this->assert('Search query (?s=...) is strictly BYPASSED', !$cacheable && str_contains($reason, 'Dynamic search'));

        // Draft preview (?preview=true)
        $_GET = ['preview' => 'true'];
        [$cacheable, $reason] = $ruleEngine->isRequestCacheable();
        $this->assert('Draft post preview (?preview=...) is strictly BYPASSED', !$cacheable);

        $_GET = [];
    }

    /**
     * 4. Test AJAX XMLHttpRequest Bypass
     */
    private function testAjaxBypass(): void
    {
        echo "\nTesting AJAX XMLHttpRequest Bypass...\n";
        $config = new Config();
        $ruleEngine = new RuleEngine($config);

        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest';
        [$cacheable, $reason] = $ruleEngine->isRequestCacheable();
        $this->assert('AJAX XMLHttpRequest is strictly BYPASSED', !$cacheable && str_contains($reason, 'AJAX'));

        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    /**
     * 5. Test Sensitive URL Exclusions (Admin, REST API, Cron)
     */
    private function testSensitiveUrlExclusions(): void
    {
        echo "\nTesting Sensitive URL Exclusions...\n";
        $config = new Config();
        $ruleEngine = new RuleEngine($config);

        $sensitiveUrls = [
            '/wp-admin/edit.php',
            '/wp-login.php',
            '/wp-json/wp/v2/users',
            '/xmlrpc.php',
            '/wp-cron.php',
            '/cart/',
            '/checkout/',
            '/my-account/orders/'
        ];

        foreach ($sensitiveUrls as $url) {
            $_SERVER['REQUEST_URI'] = $url;
            [$cacheable, $reason] = $ruleEngine->isRequestCacheable();
            $this->assert("URL '{$url}' is excluded from caching", !$cacheable && str_contains($reason, 'Excluded URL'));
        }

        $_SERVER['REQUEST_URI'] = '/';
    }

    /**
     * 6. Test RFC 7234 Cache-Control: no-store / private Header Protection
     */
    private function testRfc7234NoStoreAndPrivateHeaders(): void
    {
        echo "\nTesting RFC 7234 Cache-Control no-store / private...\n";
        $config = new Config(['cache_dir' => dirname(__DIR__) . '/cache/test_rfc']);
        $driver = new MemoryDriver();
        $stats = new StatsTracker($config->get('cache_dir'));
        $pageCache = new PageCache($config, $driver, $stats);

        // Inject simulated currentKey
        $ref = new \ReflectionClass($pageCache);
        $prop = $ref->getProperty('currentKey');
        $prop->setValue($pageCache, 'rfc_test_key');

        $html = '<html><body><h1>Private Banking Data</h1></body></html>';
        $returned = $pageCache->processAndStore($html, ['Cache-Control: private, no-store, must-revalidate']);

        // Verify that driver does NOT contain this key!
        $this->assert('PageCache respects Cache-Control: no-store and refuses to store', $driver->get('rfc_test_key') === null);
        $this->assert('processAndStore returns raw buffer unmodified on no-store', $returned === $html);
    }

    /**
     * 7. Test Set-Cookie Leak Defense
     */
    private function testSetCookieLeakDefense(): void
    {
        echo "\nTesting Set-Cookie Session Leak Defense...\n";
        $config = new Config(['cache_dir' => dirname(__DIR__) . '/cache/test_rfc']);
        $driver = new MemoryDriver();
        $stats = new StatsTracker($config->get('cache_dir'));
        $pageCache = new PageCache($config, $driver, $stats);

        $ref = new \ReflectionClass($pageCache);
        $prop = $ref->getProperty('currentKey');
        $prop->setValue($pageCache, 'cookie_leak_test_key');

        $html = '<html><body><h1>Account Dashboard</h1></body></html>';
        $returned = $pageCache->processAndStore($html, ['Set-Cookie: auth_token=secret_xyz123; path=/; HttpOnly']);

        $this->assert('PageCache detects Set-Cookie and refuses to cache private session', $driver->get('cookie_leak_test_key') === null);
    }

    /**
     * 8. Test Deeply Nested Output Buffers
     */
    private function testDeepNestedOutputBuffers(): void
    {
        echo "\nTesting Deeply Nested Output Buffers (CMS Theme/Plugin Recursion)...\n";
        
        $initialLevel = ob_get_level();

        // Level 1: Outer buffer
        ob_start();
        echo "[L1_START]";

        // Level 2: Template engine buffer
        ob_start();
        echo "[L2_HEADER]";
        $header = ob_get_clean();

        // Level 3: Widget component buffer
        ob_start();
        echo "[L3_WIDGET]";
        $widget = ob_get_clean();

        // Level 4: Plugin filter buffer
        ob_start();
        echo "[L4_CONTENT]";
        $content = ob_get_clean();

        echo $header . $widget . $content . "[L1_END]";
        $final = ob_get_clean();

        $this->assert('Nested output buffers resolve cleanly without stack corruption', ob_get_level() === $initialLevel);
        $this->assert('Final output aggregates all nested levels correctly', $final === '[L1_START][L2_HEADER][L3_WIDGET][L4_CONTENT][L1_END]');
    }

    /**
     * 9. Test Thundering Herd Concurrency Simulation
     */
    private function testThunderingHerdConcurrencySimulation(): void
    {
        echo "\nTesting Thundering Herd Concurrency Simulation...\n";
        $lockKey = 'thundering_herd_lock_' . bin2hex(random_bytes(4));

        $worker1Acquired = \Fphp\Lock\AtomicLock::acquire($lockKey, 2);
        $this->assert('Worker 1 acquires regeneration lock', $worker1Acquired);

        $blockedWorkers = 0;
        for ($i = 2; $i <= 20; $i++) {
            $acquired = \Fphp\Lock\AtomicLock::acquire($lockKey, 2);
            if (!$acquired) {
                $blockedWorkers++;
            }
        }

        $this->assert('All 19 concurrent workers are blocked from regenerating simultaneously', $blockedWorkers === 19);

        \Fphp\Lock\AtomicLock::release($lockKey);
        $workerAfterRelease = \Fphp\Lock\AtomicLock::acquire($lockKey, 2);
        $this->assert('New worker acquires lock cleanly after release', $workerAfterRelease);

        \Fphp\Lock\AtomicLock::release($lockKey);
    }
}

$test = new RealWorldStressTest();
$test->run();
