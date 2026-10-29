<?php

declare(strict_types=1);

/**
 * FPHP Cache - Benchmark Router & CLI Runner
 */

if (PHP_SAPI !== 'cli') {
    if (!isset($_GET['page'])) {
        $_GET['page'] = 'benchmark';
    }
    require_once __DIR__ . '/index.php';
    exit;
}

// CLI Execution Mode
require_once dirname(__DIR__) . '/fphp-bootstrap.php';

use Fphp\Driver\MemoryDriver;
use Fphp\Driver\FileDriver;
use Fphp\Driver\ApcuDriver;
use Fphp\Profiler\Metrics;

$iterations = 5000;
echo "\n============================================\n";
echo "   ⚡ FPHP Cache - CLI Performance Benchmark\n";
echo "   Iterations: " . number_format($iterations) . " ops\n";
echo "============================================\n\n";

$heavyWork = function ($i) {
    $arr = [];
    for ($j = 0; $j < 25; $j++) {
        $arr[] = ['id' => $j, 'hash' => hash('sha256', "item_$i" . "_$j")];
    }
    return json_encode($arr);
};

$mem = new MemoryDriver();
$file = new FileDriver(dirname(__DIR__) . '/cache/bench_file', 'bench:', false);
$apcu = new ApcuDriver('bench:');
$sample = ['id' => 999, 'auth' => true, 'roles' => ['admin', 'manager']];
$mem->set('k', $sample, 3600);
$file->set('k', $sample, 3600);
if ($apcu->isAvailable()) $apcu->set('k', $sample, 3600);

$run = function (string $name, callable $op) use ($iterations) {
    for ($i = 0; $i < 50; $i++) $op($i);
    $t0 = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) $op($i);
    $totalUs = round((hrtime(true) - $t0) / 1000, 2);
    $avgUs = round($totalUs / $iterations, 3);
    $ops = round($iterations / ($totalUs / 1000000));
    printf(" %-36s : %8.3f µs/op | %10s ops/sec\n", $name, $avgUs, number_format($ops));
    return $avgUs;
};

$uncachedUs = $run('1. Uncached Heavy Computation', fn($i) => $heavyWork($i));
$fileUs = $run('2. FPHP File Cache (SSD/NVMe)', fn() => $file->get('k'));
if ($apcu->isAvailable()) {
    $apcuUs = $run('3. FPHP APCu In-Memory RAM', fn() => $apcu->get('k'));
}
$memUs = $run('4. FPHP L0 C-RAM Per-Request', fn() => $mem->get('k'));

echo "\n--------------------------------------------\n";
printf(" Speedup (File vs Uncached)  : ~%dx faster\n", round($uncachedUs / max(0.001, $fileUs)));
printf(" Speedup (Memory vs Uncached): ~%dx faster\n", round($uncachedUs / max(0.001, $memUs)));
echo "============================================\n\n";
