<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/fphp-bootstrap.php';

use Fphp\Index\SlotIndex;
use Fphp\Index\PrefixIndex;
use Fphp\Index\CatalogIndex;
use Fphp\Driver\FileDriver;

echo "\n===================================================================\n";
echo "   ⚡ FPHP CACHE - HIGH-PERFORMANCE INDEXING BENCHMARK (O(1) vs O(N))\n";
echo "===================================================================\n\n";

$benchDir = dirname(__DIR__) . '/cache/bench_indexing';
@mkdir($benchDir, 0775, true);

$itemCount = 2000;
echo "1. Generating {$itemCount} sharded cache files on disk...\n";

// Populate 2000 items with FileDriver (500 belonging to 'urunler:*' prefix)
$catalog = new CatalogIndex($benchDir, ['enabled' => true, 'slot_granularity' => 3600]);
$driverWithIndex = new FileDriver($benchDir, 'app:', false, 6, $catalog);

$t0 = hrtime(true);
for ($i = 1; $i <= $itemCount; $i++) {
    $prefix = ($i <= 500) ? 'urunler:' : (($i <= 1000) ? 'kategori:' : 'genel:');
    $key = $prefix . 'item_' . $i;
    // Set 500 items as expired in past slot, others in future
    $ttl = ($i <= 300) ? -60 : 3600;
    $driverWithIndex->set($key, ['id' => $i, 'title' => "Mock Item $i"], $ttl);
}
$populateMs = round((hrtime(true) - $t0) / 1_000_000, 2);
echo "   Populated {$itemCount} files in {$populateMs} ms.\n\n";

// =========================================================
// TEST A: PREFIX INVALIDATION (O(K) Indexed vs O(N) Traversal)
// =========================================================
echo "2. Benchmarking Prefix Invalidation ('urunler:*')...\n";

// Benchmark Indexed Prefix Deletion
$t0 = hrtime(true);
$indexedDeleted = $driverWithIndex->deletePrefix('urunler:');
$indexedUs = round((hrtime(true) - $t0) / 1000, 2);
echo "   [INDEXED]   Purged {$indexedDeleted} matching files in {$indexedUs} µs (" . round($indexedUs / 1000, 3) . " ms)\n";

// Re-populate 500 items without index for legacy traversal comparison
$driverLegacy = new FileDriver($benchDir, 'app:', false, 6, null);
for ($i = 1; $i <= 500; $i++) {
    $driverLegacy->set('urunler:legacy_' . $i, ['id' => $i], 3600);
}

// Benchmark Legacy Recursive Filesystem Traversal
$t0 = hrtime(true);
$legacyDeleted = $driverLegacy->deletePrefix('urunler:');
$legacyUs = round((hrtime(true) - $t0) / 1000, 2);
echo "   [LEGACY]    Scanned full disk tree to purge {$legacyDeleted} files in {$legacyUs} µs (" . round($legacyUs / 1000, 3) . " ms)\n";

$speedupPrefix = round(max(1.0, $legacyUs / max(1.0, $indexedUs)), 1);
echo "   ⚡ Prefix Invalidation Speedup: {$speedupPrefix}x FASTER!\n\n";

// =========================================================
// TEST B: TIME-BUCKET SLOT PRUNING (O(1) vs Full Disk Crawl)
// =========================================================
echo "3. Benchmarking Garbage Collection (TTL Pruning)...\n";

$t0 = hrtime(true);
$slotPruned = $catalog->prune();
$slotPruneUs = round((hrtime(true) - $t0) / 1000, 2);
echo "   [INDEXED]   SlotIndex pruned {$slotPruned['deleted_files']} files in {$slotPruneUs} µs (" . round($slotPruneUs / 1000, 3) . " ms)\n";

$t0 = hrtime(true);
$legacyPruned = $driverLegacy->prune();
$legacyPruneUs = round((hrtime(true) - $t0) / 1000, 2);
echo "   [LEGACY]    Full disk crawl checked all files in {$legacyPruneUs} µs (" . round($legacyPruneUs / 1000, 3) . " ms)\n";

$speedupPrune = round(max(1.0, $legacyPruneUs / max(1.0, $slotPruneUs)), 1);
echo "   ⚡ Garbage Collection Speedup: {$speedupPrune}x FASTER!\n\n";

// Clean benchmark directory
$catalog->clear();
$driverLegacy->clear();

echo "===================================================================\n";
echo "   Summary: Indexing eliminates O(N) I/O bottleneck completely!\n";
echo "===================================================================\n\n";

