<?php
/**
 * FPHP Cache - OPcache Preloading Script
 * Add to php.ini: opcache.preload = "C:\\MAMP\\htdocs\\fphpf/preload.php"
 */

$files = array (
  0 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Adapter\\Psr16Cache.php',
  1 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Adapter\\Psr6CacheItem.php',
  2 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Adapter\\Psr6CachePool.php',
  3 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Adapter\\PsrShims.php',
  4 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Adaptive\\PidController.php',
  5 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Config.php',
  6 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Driver\\ApcuDriver.php',
  7 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Driver\\CacheDriverInterface.php',
  8 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Driver\\FileDriver.php',
  9 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Driver\\HybridDriver.php',
  10 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Driver\\MemoryDriver.php',
  11 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Driver\\RedisDriver.php',
  12 => 'C:\\MAMP\\htdocs\\fphpf\\src\\FphpCache.php',
  13 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Fragment\\FragmentCache.php',
  14 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Fragment\\HolePuncher.php',
  15 => 'C:\\MAMP\\htdocs\\fphpf\\src\\FullPage\\BytecodeStore.php',
  16 => 'C:\\MAMP\\htdocs\\fphpf\\src\\FullPage\\HeaderManager.php',
  17 => 'C:\\MAMP\\htdocs\\fphpf\\src\\FullPage\\PageCache.php',
  18 => 'C:\\MAMP\\htdocs\\fphpf\\src\\FullPage\\RuleEngine.php',
  19 => 'C:\\MAMP\\htdocs\\fphpf\\src\\FullPage\\StreamStore.php',
  20 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Lock\\AtomicLock.php',
  21 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Optimizer\\AssetOptimizer.php',
  22 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Optimizer\\Compression.php',
  23 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Optimizer\\Minifier.php',
  24 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Optimizer\\OpcacheManager.php',
  25 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Optimizer\\ServerConfigGenerator.php',
  26 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Profiler\\Metrics.php',
  27 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Profiler\\StatsTracker.php',
  28 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Reactive\\LineageGraph.php',
  29 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Tags\\TaggedCache.php',
  30 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Tags\\TagManager.php',
  31 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Warmer\\CacheWarmer.php',
  32 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Warmer\\MarkovWarmer.php',
  33 => 'C:\\MAMP\\htdocs\\fphpf\\src\\Warmer\\SpeculationRulesBridge.php',
);

foreach ($files as $file) {
    if (file_exists($file)) {
        opcache_compile_file($file);
    }
}
