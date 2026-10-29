<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/fphp-bootstrap.php';

use Fphp\FphpCache;
use Fphp\Driver\MemoryDriver;
use Fphp\Driver\FileDriver;
use Fphp\Driver\ApcuDriver;
use Fphp\Profiler\Metrics;

// Enable web or CLI execution
$isCli = PHP_SAPI === 'cli';
$iterations = isset($_GET['iterations']) ? max(100, min(20000, (int)$_GET['iterations'])) : 5000;

function runBenchmark(string $name, callable $operation, int $iterations): array
{
    // Warmup
    for ($i = 0; $i < 50; $i++) {
        $operation($i);
    }

    Metrics::start($name);
    for ($i = 0; $i < $iterations; $i++) {
        $operation($i);
    }
    $micro = Metrics::stopMicroseconds($name);
    $avgUs = round($micro / $iterations, 3);
    $opsSec = round($iterations / ($micro / 1000000));

    return [
        'name' => $name,
        'total_us' => $micro,
        'total_ms' => round($micro / 1000, 2),
        'avg_us' => $avgUs,
        'ops_sec' => number_format($opsSec),
        'raw_ops' => $opsSec
    ];
}

// 1. Simulate heavy uncached computation (complex JSON serialization, hashing, array manipulation)
$heavyWork = function ($i) {
    $data = [];
    for ($j = 0; $j < 30; $j++) {
        $data[] = [
            'id' => $j,
            'token' => hash('sha256', "item_$i" . "_$j"),
            'price' => rand(10, 1000)
        ];
    }
    return json_encode($data);
};

// Setup Drivers
$memDriver = new MemoryDriver();
$fileDriver = new FileDriver(dirname(__DIR__) . '/cache/bench_file', 'bench:', false);
$compFileDriver = new FileDriver(dirname(__DIR__) . '/cache/bench_comp_file', 'bench_comp:', true);
$apcuDriver = new ApcuDriver('bench_apcu:');

// Prepare cached items
$sampleData = ['user_id' => 999, 'role' => 'administrator', 'features' => ['billing', 'api', 'export'], 'token' => bin2hex(random_bytes(16))];
$memDriver->set('bench_key', $sampleData, 3600);
$fileDriver->set('bench_key', $sampleData, 3600);
$compFileDriver->set('bench_key', $sampleData, 3600);
if ($apcuDriver->isAvailable()) {
    $apcuDriver->set('bench_key', $sampleData, 3600);
}

$results = [];

// Benchmark 1: Uncached computation
$results['uncached'] = runBenchmark('Uncached Heavy Processing', function ($i) use ($heavyWork) {
    $heavyWork($i);
}, $iterations);

// Benchmark 2: Atomic File Cache Read
$results['file_read'] = runBenchmark('FPHP File Cache (SSD/NVMe Read)', function () use ($fileDriver) {
    return $fileDriver->get('bench_key');
}, $iterations);

// Benchmark 3: Compressed File Cache Read
$results['comp_file_read'] = runBenchmark('FPHP Compressed File Cache Read', function () use ($compFileDriver) {
    return $compFileDriver->get('bench_key');
}, $iterations);

// Benchmark 4: APCu In-Memory Cache Read (if available)
if ($apcuDriver->isAvailable()) {
    $results['apcu_read'] = runBenchmark('FPHP APCu In-Memory RAM Read', function () use ($apcuDriver) {
        return $apcuDriver->get('bench_key');
    }, $iterations);
}

// Benchmark 5: L0 In-Request Memory Read
$results['memory_read'] = runBenchmark('FPHP L0 Per-Request RAM Read', function () use ($memDriver) {
    return $memDriver->get('bench_key');
}, $iterations);

// Speedup calculation against uncached
$baseUs = $results['uncached']['avg_us'];
foreach ($results as $k => $res) {
    $speedup = $res['avg_us'] > 0 ? round($baseUs / $res['avg_us'], 1) : 1;
    $results[$k]['speedup'] = $speedup . 'x Hızlı';
}

// Cleanup bench files
$fileDriver->clear();
$compFileDriver->clear();

if ($isCli) {
    echo "\n========================================================================================\n";
    echo "                   FPHP CACHE - HIGH-RESOLUTION BENCHMARK REPORT                         \n";
    echo "                      Iterations: " . number_format($iterations) . " operations                              \n";
    echo "========================================================================================\n";
    printf("%-35s | %-12s | %-12s | %-12s | %-10s\n", "Engine / Operation", "Avg Latency", "Throughput", "Total Time", "Speedup");
    echo "----------------------------------------------------------------------------------------\n";
    foreach ($results as $r) {
        printf(
            "%-35s | %-12s | %-12s | %-12s | %-10s\n",
            $r['name'],
            $r['avg_us'] . ' µs',
            $r['ops_sec'] . ' op/s',
            $r['total_ms'] . ' ms',
            $r['speedup']
        );
    }
    echo "========================================================================================\n\n";
    exit(0);
}

// If accessed via browser, render HTML dashboard
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FPHP Cache - Mikrosaniye Hız Karşılaştırma Testi</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #090d16;
            --surface: #111827;
            --surface-card: rgba(17, 24, 39, 0.75);
            --border: rgba(255, 255, 255, 0.08);
            --border-hover: rgba(99, 102, 241, 0.4);
            --primary: #6366f1;
            --primary-glow: rgba(99, 102, 241, 0.25);
            --accent-green: #10b981;
            --accent-cyan: #06b6d4;
            --text-main: #f9fafb;
            --text-muted: #9ca3af;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, sans-serif;
            background-color: var(--bg);
            color: var(--text-main);
            min-height: 100vh;
            padding: 2.5rem 1.5rem;
            background-image: 
                radial-gradient(circle at 15% 20%, rgba(99, 102, 241, 0.12) 0%, transparent 40%),
                radial-gradient(circle at 85% 75%, rgba(6, 182, 212, 0.1) 0%, transparent 40%);
        }

        .container { max-width: 1100px; margin: 0 auto; }

        .navbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.25rem 2rem;
            background: var(--surface-card);
            border: 1px solid var(--border);
            border-radius: 1rem;
            backdrop-filter: blur(16px);
            margin-bottom: 2.5rem;
        }
        .logo {
            font-size: 1.35rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            background: linear-gradient(135deg, #ffffff 40%, #a5b4fc 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .nav-links {
            display: flex;
            gap: 1.25rem;
            align-items: center;
        }
        .nav-links a {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 500;
            transition: color 0.2s ease;
        }
        .nav-links a:hover, .nav-links a.active {
            color: var(--text-main);
        }
        
        .header {
            text-align: center;
            margin-bottom: 3rem;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.35rem 0.9rem;
            border-radius: 9999px;
            background: rgba(99, 102, 241, 0.15);
            border: 1px solid rgba(99, 102, 241, 0.3);
            color: #a5b4fc;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 1rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .header h1 {
            font-size: 2.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, #ffffff 30%, #a5b4fc 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.75rem;
        }
        .header p {
            color: var(--text-muted);
            font-size: 1.1rem;
            max-width: 650px;
            margin: 0 auto;
        }

        .bench-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2.5rem;
        }
        .bench-card {
            background: var(--surface-card);
            border: 1px solid var(--border);
            border-radius: 1rem;
            padding: 1.5rem;
            backdrop-filter: blur(16px);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
        }
        .bench-card:hover {
            border-color: var(--border-hover);
            transform: translateY(-4px);
            box-shadow: 0 12px 30px -10px var(--primary-glow);
        }
        .bench-card.highlight {
            border-color: rgba(16, 185, 129, 0.4);
            background: linear-gradient(145deg, rgba(16, 185, 129, 0.08), rgba(17, 24, 39, 0.9));
        }

        .bench-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .speedup-pill {
            font-size: 0.8rem;
            font-weight: 700;
            padding: 0.2rem 0.6rem;
            border-radius: 6px;
            background: rgba(16, 185, 129, 0.2);
            color: var(--accent-green);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .latency {
            font-family: 'JetBrains Mono', monospace;
            font-size: 2.25rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 0.5rem;
        }
        .latency small {
            font-size: 1rem;
            font-weight: 500;
            color: var(--text-muted);
            margin-left: 0.25rem;
        }
        .ops {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.9rem;
            color: var(--accent-cyan);
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .table-card {
            background: var(--surface-card);
            border: 1px solid var(--border);
            border-radius: 1rem;
            overflow: hidden;
            backdrop-filter: blur(16px);
            margin-bottom: 2.5rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        th {
            background: rgba(255, 255, 255, 0.03);
            padding: 1rem 1.5rem;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
        }
        td {
            padding: 1.1rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            font-size: 0.95rem;
        }
        tr:last-child td { border-bottom: none; }
        .mono { font-family: 'JetBrains Mono', monospace; }

        .btn-group {
            display: flex;
            justify-content: center;
            gap: 1rem;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: 0.6rem;
            font-size: 0.95rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .btn-primary {
            background: var(--primary);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .btn-primary:hover {
            background: #4f46e5;
            box-shadow: 0 4px 15px var(--primary-glow);
        }
        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-main);
            border: 1px solid var(--border);
        }
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="navbar">
            <div class="logo">
                <span>⚡</span> FPHP Cache
            </div>
            <nav class="nav-links">
                <a href="index.php">🏠 Ana Sayfa (Canlı Hızlandırıcı)</a>
                <a href="dashboard.php">📊 Canlı Yönetim Paneli</a>
                <a href="benchmark.php" class="active">⚡ Hız Testi (Benchmark)</a>
                <a href="sitemap.xml" target="_blank">🗺️ Site Haritası (Sitemap.xml)</a>
            </nav>
        </header>

        <div class="header">
            <div class="badge">⚡ Real-time Benchmark Engine</div>
            <h1>FPHP Cache Mikrosaniye Hız Testi</h1>
            <p>Standart veritabanı/şablon işlem döngüsü ile FPHP çok katmanlı mikro-önbellek motorunun gerçek zamanlı performans karşılaştırması.</p>
        </div>

        <div class="bench-grid">
            <?php foreach ($results as $res): ?>
                <div class="bench-card <?= str_contains($res['name'], 'RAM') || str_contains($res['name'], 'L0') ? 'highlight' : '' ?>">
                    <div class="bench-title">
                        <span><?= htmlspecialchars($res['name']) ?></span>
                        <span class="speedup-pill"><?= htmlspecialchars($res['speedup']) ?></span>
                    </div>
                    <div class="latency"><?= htmlspecialchars((string)$res['avg_us']) ?><small>µs</small></div>
                    <div class="ops">
                        <span>⚡</span>
                        <span><?= htmlspecialchars($res['ops_sec']) ?> işlem / sn</span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Depolama Katmanı</th>
                        <th>Ortalama Yanıt</th>
                        <th>Kapasite (Throughput)</th>
                        <th>Toplam Süre (<?= number_format($iterations) ?> İşlem)</th>
                        <th>Hız Artışı</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $res): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($res['name']) ?></strong></td>
                            <td class="mono" style="color: var(--accent-green);"><?= htmlspecialchars((string)$res['avg_us']) ?> µs</td>
                            <td class="mono"><?= htmlspecialchars($res['ops_sec']) ?> op/s</td>
                            <td class="mono"><?= htmlspecialchars((string)$res['total_ms']) ?> ms</td>
                            <td><span class="speedup-pill"><?= htmlspecialchars($res['speedup']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="btn-group">
            <a href="?iterations=5000" class="btn btn-primary">🔄 Testi Yeniden Çalıştır (5.000 İşlem)</a>
            <a href="?iterations=15000" class="btn btn-secondary">🚀 Ağır Yük Testi (15.000 İşlem)</a>
            <a href="dashboard.php" class="btn btn-secondary">📊 Yönetim Paneline Git</a>
        </div>
    </div>
</body>
</html>
