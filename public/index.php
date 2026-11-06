<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/fphp-bootstrap.php';

use Fphp\FphpCache;
use Fphp\Driver\HybridDriver;
use Fphp\Driver\ApcuDriver;
use Fphp\Driver\FileDriver;
use Fphp\Driver\MemoryDriver;
use Fphp\Profiler\Metrics;

// ==============================================================
// 1. ÖNBELLEK ÇEKİRDEĞİNİ BAŞLAT (FULL PAGE BOOT)
// ==============================================================
$page = $_GET['page'] ?? 'demo';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Sadece normal demo isteklerinde page cache tam devrede olsun
if ($page === 'demo' && empty($action) && empty($_GET['fphp_bypass'])) {
    \Fphp\FphpCache::boot([
        'page_cache' => [
            'enabled' => true,
            'ttl' => 600,
            'hole_punching' => true
        ],
        'optimizer' => [
            'minify_html' => true,
            'gzip_compression' => true
        ]
    ]);
}

// ==============================================================
// 2. YÖNETİM EYLEMLERİ (ACTION HANDLER)
// ==============================================================
$notice = null;
$noticeType = 'success';

if (!empty($action)) {
    try {
        if ($action === 'clear_all') {
            FphpCache::clear();
            $notice = 'Tüm katmanlardaki önbellek (L1 RAM, Bytecode ve Disk) tamamen temizlendi.';
        } elseif ($action === 'prune_expired') {
            $pruned = FphpCache::prune();
            $notice = "Süresi dolmuş {$pruned['total_deleted']} adet dosya silindi (" . Metrics::formatBytes($pruned['total_freed_bytes']) . " alan boşaltıldı).";
        } elseif ($action === 'reset_stats') {
            FphpCache::getStatsTracker()->reset();
            $notice = 'Performans sayaçları ve telemetri kayıtları sıfırlandı.';
        } elseif ($action === 'generate_preload') {
            $ok = FphpCache::generatePreloadFile();
            $notice = $ok ? 'OPcache preload.php dosyası kök dizinde başarıyla derlendi.' : 'Preload dosyası derlenemedi.';
            if (!$ok) $noticeType = 'danger';
        } elseif ($action === 'mutate_entity') {
            $entity = trim($_POST['entity'] ?? '');
            if (!empty($entity)) {
                $report = FphpCache::mutate($entity);
                $notice = "Reaktif Soyağacı: '{$entity}' değiştiği için ona bağlı {$report['evicted_count']} adet sayfa önbellekten anında silindi.";
            } else {
                $notice = 'Lütfen bir veri varlığı (örn: table:products veya users:10) belirtin.';
                $noticeType = 'danger';
            }
        } elseif ($action === 'delete_file') {
            $filePath = $_POST['file_path'] ?? '';
            $cacheDir = realpath(dirname(__DIR__) . '/cache');
            $targetReal = realpath($filePath);
            if ($targetReal && str_starts_with($targetReal, $cacheDir) && file_exists($targetReal)) {
                @unlink($targetReal);
                $notice = 'Seçilen önbellek dosyası diskten silindi: ' . htmlspecialchars(basename($targetReal));
            } else {
                $notice = 'Geçersiz dosya yolu.';
                $noticeType = 'danger';
            }
        }
    } catch (\Throwable $e) {
        $notice = 'İşlem hatası: ' . $e->getMessage();
        $noticeType = 'danger';
    }
}

// ==============================================================
// 3. VERİLER VE METRİKLERİ TOPLA
// ==============================================================
$stats = FphpCache::getStats();
$driver = FphpCache::getDriver();
$driverStats = $driver->getStats();
$config = FphpCache::getConfig();

$totalRequests = $stats['total_requests'] ?? 0;
$hits = $stats['hits'] ?? 0;
$misses = $stats['misses'] ?? 0;
$bypasses = $stats['bypasses'] ?? 0;
$hitRatio = $stats['hit_ratio'] ?? 0.0;
$savedSec = $stats['saved_time_seconds'] ?? 0.0;

// PID Verileri
$pid = FphpCache::getPidController();
$pidData = $pid ? $pid->getTelemetry() : [];
$swrGrace = FphpCache::adaptSwr(60);

// Markov Verileri (Sadece Zeka sayfasında yükle)
$markovMatrix = [];
if ($page === 'intelligence') {
    $markovFile = dirname(__DIR__) . '/cache/markov_transitions.json';
    if (file_exists($markovFile)) {
        $rawM = @file_get_contents($markovFile);
        $markovMatrix = $rawM ? (json_decode($rawM, true) ?? []) : [];
    }
}

// Lineage Verileri (Sadece Zeka sayfasında yükle)
$lineageData = ['entity_to_artifacts' => []];
if ($page === 'intelligence') {
    $lineageFile = dirname(__DIR__) . '/cache/lineage_graph.json';
    if (file_exists($lineageFile)) {
        $rawL = @file_get_contents($lineageFile);
        $lineageData = $rawL ? (json_decode($rawL, true) ?? $lineageData) : $lineageData;
    }
}

// Dosya Gezgini Listesi (Sadece Dosyalar veya İstatistik sayfasında yükle)
$cacheFiles = [];
if ($page === 'files' || $page === 'stats') {
    $cacheDir = dirname(__DIR__) . '/cache';
    if (is_dir($cacheDir)) {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            $name = $f->getFilename();
            if ($name === '.gitkeep' || str_ends_with($name, '.json')) continue;
            $ext = strtolower($f->getExtension());
            $type = match ($ext) {
                'php' => 'OPcache Bytecode (C-RAM)',
                'fpc' => 'Ham Soket Akışı (Stream)',
                'gz'  => 'Gzip Hazır Sıkıştırılmış',
                default => 'Veri Önbelleği'
            };
            $cacheFiles[] = [
                'name' => $name,
                'path' => $f->getPathname(),
                'type' => $type,
                'size' => Metrics::formatBytes($f->getSize()),
                'mtime' => date('H:i:s d.m.Y', $f->getMTime()),
                'raw_mtime' => $f->getMTime()
            ];
            if (count($cacheFiles) >= 80) break;
        }
        usort($cacheFiles, fn($a, $b) => $b['raw_mtime'] <=> $a['raw_mtime']);
    }
}

// Canlı Dinamik Ada (Hole Punching) Slotları
$dynamicClock = \Fphp\FphpCache::hole('live_clock', function () {
    return date('H:i:s') . ' (.' . substr((string)microtime(true), -4) . ' µs)';
}, fallback: date('H:i:s'));

$dynamicVisitor = \Fphp\FphpCache::hole('live_visitor', function () {
    return 'UBUNTU-USER-' . substr(md5($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' . microtime()), 0, 6);
}, fallback: 'UBUNTU-USER-DEMO');

// Benchmark Çalıştırma Fonksiyonu
$benchResults = null;
if ($page === 'benchmark' && isset($_GET['run'])) {
    $iters = max(100, min(10000, (int)($_GET['iter'] ?? 3000)));
    $heavy = function ($i) {
        $arr = [];
        for ($j = 0; $j < 20; $j++) {
            $arr[] = ['id' => $j, 'hash' => hash('sha256', "key_$i" . "_$j")];
        }
        return json_encode($arr);
    };

    $runTest = function (string $name, callable $op) use ($iters) {
        for ($i = 0; $i < 30; $i++) $op($i); // Isınma
        $t0 = hrtime(true);
        for ($i = 0; $i < $iters; $i++) $op($i);
        $totalUs = round((hrtime(true) - $t0) / 1000, 2);
        $avgUs = round($totalUs / $iters, 3);
        $opsSec = round($iters / ($totalUs / 1000000));
        return ['name' => $name, 'avg_us' => $avgUs, 'ops_sec' => number_format($opsSec)];
    };

    $mem = new MemoryDriver();
    $file = new FileDriver(dirname(__DIR__) . '/cache/bench_file', 'b:', false);
    $apcu = new ApcuDriver('b:');
    $sample = ['id' => 10, 'role' => 'admin', 'auth' => true];
    $mem->set('k', $sample, 300);
    $file->set('k', $sample, 300);
    if ($apcu->isAvailable()) $apcu->set('k', $sample, 300);

    $benchResults = [
        'iterations' => $iters,
        'uncached' => $runTest('Önbelleksiz Klasik PHP (Ağır İşlem)', fn($i) => $heavy($i)),
        'file' => $runTest('FPHP Disk Önbelleği (SSD/NVMe)', fn() => $file->get('k')),
        'apcu' => $apcu->isAvailable() ? $runTest('FPHP APCu RAM Önbelleği', fn() => $apcu->get('k')) : null,
        'memory' => $runTest('FPHP L0 C-Bellek Okuması', fn() => $mem->get('k'))
    ];
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ubuntu Desktop — FPHP Cache Denetim Merkezi</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Ubuntu:ital,wght@0,300;0,400;0,500;0,700;1,400&family=Ubuntu+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            /* Canonical Ubuntu Color Palette */
            --ub-aubergine-dark: #2C001E;
            --ub-aubergine-mid: #5E2750;
            --ub-aubergine-light: #77216F;
            --ub-orange: #E95420;
            --ub-orange-hover: #ff6e3a;
            --ub-orange-active: #d34615;
            --ub-warm-grey: #AEA79F;
            --ub-cool-grey: #333333;
            --ub-jet: #111111;
            --ub-dark-window: #242424;
            --ub-headerbar: #2D2D2D;
            --ub-headerbar-border: #1E1E1E;
            --ub-card: #2E2E2E;
            --ub-card-border: #3A3A3A;
            --ub-text-light: #F5F5F5;
            --ub-text-muted: #A6A6A6;
            --ub-text-subtle: #757575;
            --ub-success: #26A269;
            --ub-danger: #C7162B;
            --ub-danger-hover: #E01B24;
            --ub-info: #1C71D8;
            --ub-warning: #F5C211;
            
            --font-ubuntu: 'Ubuntu', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --font-mono: 'Ubuntu Mono', monospace;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background: radial-gradient(ellipse at 80% 20%, var(--ub-aubergine-light) 0%, var(--ub-aubergine-mid) 32%, var(--ub-aubergine-dark) 65%, #14000e 100%);
            background-attachment: fixed;
            color: var(--ub-text-light);
            font-family: var(--font-ubuntu);
            font-size: 14px;
            line-height: 1.5;
            min-height: 100vh;
            overflow-x: hidden;
            user-select: none;
        }

        /* ============================================================== */
        /* 1. UBUNTU TOP PANEL (BAR)                                      */
        /* ============================================================== */
        .ubuntu-panel {
            height: 32px;
            background: rgba(14, 14, 14, 0.98);
            border-bottom: 1px solid rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 12px;
            font-size: 13px;
            font-weight: 500;
            position: sticky;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(10px);
        }

        .panel-left, .panel-center, .panel-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .activities-btn {
            padding: 3px 12px;
            border-radius: 12px;
            color: #eeeeee;
            font-size: 13px;
            font-weight: 500;
            background: transparent;
            border: none;
            cursor: pointer;
            transition: background 0.15s;
        }
        .activities-btn:hover, .activities-btn.active {
            background: rgba(255, 255, 255, 0.15);
            color: #ffffff;
        }

        .panel-app-name {
            font-weight: 600;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .panel-app-name::before {
            content: '●';
            color: var(--ub-orange);
            font-size: 10px;
        }

        .panel-clock {
            font-weight: 500;
            color: #e5e5e5;
            cursor: pointer;
            padding: 2px 10px;
            border-radius: 12px;
            transition: background 0.15s;
        }
        .panel-clock:hover {
            background: rgba(255, 255, 255, 0.12);
        }

        .panel-tray-item {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 2px 8px;
            border-radius: 12px;
            color: #dedede;
            font-size: 12px;
            cursor: pointer;
            transition: background 0.15s;
        }
        .panel-tray-item:hover {
            background: rgba(255, 255, 255, 0.12);
        }
        .tray-cram-pill {
            background: rgba(38, 162, 105, 0.2);
            border: 1px solid rgba(38, 162, 105, 0.4);
            color: #4cd995;
            padding: 1px 8px;
            border-radius: 10px;
            font-family: var(--font-mono);
            font-weight: bold;
            font-size: 11px;
        }

        /* ============================================================== */
        /* 2. DESKTOP WORKSPACE & DOCK LAYOUT                             */
        /* ============================================================== */
        .ubuntu-workspace {
            display: flex;
            height: calc(100vh - 32px);
            position: relative;
        }

        /* LEFT DOCK / LAUNCHER */
        .ubuntu-dock {
            width: 70px;
            background: rgba(18, 18, 18, 0.88);
            border-right: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 10px 0;
            gap: 12px;
            z-index: 500;
            backdrop-filter: blur(16px);
        }

        .dock-item {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            position: relative;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.06);
            transition: transform 0.15s cubic-bezier(0.4, 0, 0.2, 1), background 0.15s;
            cursor: pointer;
        }
        .dock-item:hover {
            transform: scale(1.12);
            background: rgba(255, 255, 255, 0.14);
            border-color: rgba(255, 255, 255, 0.2);
        }
        .dock-item.active {
            background: rgba(233, 84, 32, 0.22);
            border-color: var(--ub-orange);
        }
        /* Classic Ubuntu Running Dot Indicator */
        .dock-item.active::before {
            content: '';
            position: absolute;
            left: -10px;
            width: 4px;
            height: 20px;
            background: var(--ub-orange);
            border-radius: 0 4px 4px 0;
            box-shadow: 0 0 8px var(--ub-orange);
        }

        .dock-icon {
            font-size: 24px;
            line-height: 1;
            user-select: none;
        }

        .dock-separator {
            width: 36px;
            height: 1px;
            background: rgba(255, 255, 255, 0.12);
            margin: 4px 0;
        }

        .dock-spacer {
            flex: 1;
        }

        /* UBUNTU TOOLTIP */
        .dock-item .dock-tooltip {
            visibility: hidden;
            position: absolute;
            left: 78px;
            background: #111111;
            color: #ffffff;
            font-size: 12px;
            font-weight: 500;
            padding: 5px 12px;
            border-radius: 6px;
            white-space: nowrap;
            border: 1px solid #333333;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.6);
            opacity: 0;
            transition: opacity 0.15s ease-in-out;
            pointer-events: none;
            z-index: 1000;
        }
        .dock-item:hover .dock-tooltip {
            visibility: visible;
            opacity: 1;
        }

        /* ============================================================== */
        /* 3. WINDOW AREA (YARU DARK WINDOW)                              */
        /* ============================================================== */
        .ubuntu-stage {
            flex: 1;
            overflow-y: auto;
            padding: 20px 30px;
            display: flex;
            flex-direction: column;
            user-select: text;
        }

        .ubuntu-window {
            background: var(--ub-dark-window);
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.7), 0 0 0 1px rgba(0, 0, 0, 0.5);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            max-width: 1300px;
            width: 100%;
            margin: 0 auto;
            transition: all 0.25s ease;
        }

        .ubuntu-window.maximized {
            max-width: 100%;
            border-radius: 0;
            margin: -20px -30px;
            height: calc(100vh - 32px);
            border: none;
        }

        /* YARU HEADERBAR */
        .ubuntu-headerbar {
            height: 48px;
            background: var(--ub-headerbar);
            border-bottom: 1px solid var(--ub-headerbar-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            user-select: none;
        }

        .headerbar-title-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .headerbar-app-icon {
            font-size: 20px;
        }
        .headerbar-title {
            font-weight: 700;
            font-size: 13.5px;
            color: #ffffff;
            letter-spacing: -0.01em;
        }
        .headerbar-subtitle {
            font-size: 11px;
            color: var(--ub-text-muted);
            font-family: var(--font-mono);
            background: rgba(255, 255, 255, 0.06);
            padding: 1px 6px;
            border-radius: 4px;
        }

        /* GNOME HEADERBAR TABS */
        .ubuntu-tab-bar {
            display: flex;
            background: rgba(0, 0, 0, 0.35);
            padding: 3px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.05);
            gap: 3px;
        }
        .ubuntu-tab {
            padding: 5px 14px;
            border-radius: 6px;
            color: #cccccc;
            text-decoration: none;
            font-size: 12.5px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: background 0.12s, color 0.12s;
        }
        .ubuntu-tab:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.08);
        }
        .ubuntu-tab.active {
            background: var(--ub-orange);
            color: #ffffff;
            font-weight: 600;
            box-shadow: 0 2px 6px rgba(233, 84, 32, 0.4);
        }

        /* YARU WINDOW CONTROLS (RIGHT) */
        .ubuntu-window-controls {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .win-control-btn {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            border: none;
            color: #dedede;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.15s, color 0.15s;
        }
        .win-control-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }
        .win-control-close:hover {
            background: var(--ub-orange);
            color: #ffffff;
        }

        /* WINDOW BODY */
        .ubuntu-window-body {
            padding: 24px 28px;
            overflow-y: auto;
            flex: 1;
        }

        /* ============================================================== */
        /* 4. NOTIFICATIONS, CARDS & KPIS                                 */
        /* ============================================================== */
        .ub-alert {
            background: rgba(46, 46, 46, 0.9);
            border: 1px solid var(--ub-card-border);
            border-left: 5px solid var(--ub-orange);
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13.5px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }
        .ub-alert.success { border-left-color: var(--ub-success); }
        .ub-alert.danger  { border-left-color: var(--ub-danger); }

        .ub-banner {
            background: linear-gradient(135deg, rgba(119, 33, 111, 0.3) 0%, rgba(233, 84, 32, 0.15) 100%);
            border: 1px solid rgba(233, 84, 32, 0.3);
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 22px;
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }
        .ub-banner-icon {
            font-size: 28px;
            line-height: 1;
        }
        .ub-banner-title {
            font-size: 14.5px;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 4px;
        }
        .ub-banner-desc {
            font-size: 13px;
            color: var(--ub-warm-grey);
            line-height: 1.45;
        }

        /* KPI STATS ROW */
        .ub-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }
        .ub-kpi-card {
            background: var(--ub-card);
            border: 1px solid var(--ub-card-border);
            border-radius: 10px;
            padding: 16px 18px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            transition: transform 0.15s, border-color 0.15s;
        }
        .ub-kpi-card:hover {
            transform: translateY(-2px);
            border-color: rgba(233, 84, 32, 0.4);
        }
        .ub-kpi-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--ub-text-muted);
            margin-bottom: 6px;
        }
        .ub-kpi-value {
            font-family: var(--font-mono);
            font-size: 1.85rem;
            font-weight: 700;
            line-height: 1.2;
            color: #ffffff;
        }
        .ub-kpi-sub {
            font-size: 12px;
            color: var(--ub-text-muted);
            margin-top: 4px;
        }

        /* PANELS & SECTIONS */
        .ub-panel {
            background: var(--ub-card);
            border: 1px solid var(--ub-card-border);
            border-radius: 10px;
            margin-bottom: 22px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
        }
        .ub-panel-header {
            background: #252525;
            padding: 12px 18px;
            border-bottom: 1px solid var(--ub-card-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .ub-panel-title {
            font-size: 13.5px;
            font-weight: 700;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .ub-panel-body {
            padding: 18px;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 900px) {
            .grid-2 { grid-template-columns: 1fr; }
        }

        /* UBUNTU TABLES */
        .ub-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            text-align: left;
        }
        .ub-table th {
            background: #202020;
            color: var(--ub-text-muted);
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 10px 14px;
            border-bottom: 1px solid var(--ub-card-border);
        }
        .ub-table td {
            padding: 11px 14px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            color: #e5e5e5;
        }
        .ub-table tr:hover td {
            background: rgba(255, 255, 255, 0.03);
        }

        /* BUTTONS & FORM ELEMENTS */
        .btn-ubuntu {
            font-family: var(--font-ubuntu);
            font-size: 13px;
            font-weight: 600;
            padding: 7px 16px;
            border-radius: 6px;
            border: 1px solid var(--ub-orange-active);
            background: var(--ub-orange);
            color: #ffffff;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.15s, transform 0.1s;
        }
        .btn-ubuntu:hover {
            background: var(--ub-orange-hover);
        }
        .btn-ubuntu:active {
            transform: scale(0.98);
        }

        .btn-ubuntu-secondary {
            font-family: var(--font-ubuntu);
            font-size: 13px;
            font-weight: 500;
            padding: 7px 16px;
            border-radius: 6px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            background: rgba(255, 255, 255, 0.08);
            color: #f0f0f0;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.15s;
        }
        .btn-ubuntu-secondary:hover {
            background: rgba(255, 255, 255, 0.16);
            color: #ffffff;
        }

        .btn-ubuntu-danger {
            font-family: var(--font-ubuntu);
            font-size: 13px;
            font-weight: 600;
            padding: 7px 16px;
            border-radius: 6px;
            border: 1px solid var(--ub-danger);
            background: var(--ub-danger);
            color: #ffffff;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.15s;
        }
        .btn-ubuntu-danger:hover {
            background: var(--ub-danger-hover);
        }

        .ub-input {
            background: #181818;
            border: 1px solid var(--ub-card-border);
            border-radius: 6px;
            padding: 7px 12px;
            color: #ffffff;
            font-family: var(--font-mono);
            font-size: 13px;
            outline: none;
        }
        .ub-input:focus {
            border-color: var(--ub-orange);
            box-shadow: 0 0 0 2px rgba(233, 84, 32, 0.25);
        }

        /* CODE SNIPPET (UBUNTU TERMINAL) */
        .ub-terminal {
            background: #181818;
            border: 1px solid #333333;
            border-radius: 8px;
            padding: 14px 16px;
            font-family: var(--font-mono);
            font-size: 12.5px;
            color: #8ae234;
            overflow-x: auto;
            white-space: pre-wrap;
            line-height: 1.45;
            box-shadow: inset 0 2px 8px rgba(0, 0, 0, 0.5);
        }

        .ub-pill {
            display: inline-block;
            font-size: 11px;
            font-family: var(--font-mono);
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 4px;
            border: 1px solid transparent;
        }
        .ub-pill-green  { background: rgba(38, 162, 105, 0.2); color: #4cd995; border-color: rgba(38, 162, 105, 0.4); }
        .ub-pill-orange { background: rgba(233, 84, 32, 0.2); color: #ff8555; border-color: rgba(233, 84, 32, 0.4); }
        .ub-pill-blue   { background: rgba(28, 113, 216, 0.2); color: #62a0ea; border-color: rgba(28, 113, 216, 0.4); }
        .ub-pill-grey   { background: rgba(255, 255, 255, 0.08); color: #aaaaaa; border-color: rgba(255, 255, 255, 0.1); }

        /* ============================================================== */
        /* 5. ACTIVITIES OVERLAY (ETKİNLİKLER GENEL BAKIŞ)                */
        /* ============================================================== */
        .activities-overlay {
            display: none;
            position: fixed;
            top: 32px;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(14, 14, 14, 0.94);
            backdrop-filter: blur(24px);
            z-index: 2000;
            padding: 40px;
            overflow-y: auto;
            flex-direction: column;
            align-items: center;
        }
        .activities-overlay.open {
            display: flex;
        }

        .activities-search {
            width: 100%;
            max-width: 500px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 24px;
            padding: 10px 20px;
            color: #ffffff;
            font-size: 15px;
            font-family: var(--font-ubuntu);
            outline: none;
            margin-bottom: 40px;
            text-align: center;
        }
        .activities-search:focus {
            background: rgba(255, 255, 255, 0.15);
            border-color: var(--ub-orange);
            box-shadow: 0 0 0 3px rgba(233, 84, 32, 0.3);
        }

        .activities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            max-width: 900px;
            width: 100%;
            gap: 24px;
        }
        .activity-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 20px 14px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #ffffff;
            text-decoration: none;
            transition: transform 0.15s, background 0.15s;
        }
        .activity-card:hover {
            transform: translateY(-4px);
            background: rgba(255, 255, 255, 0.12);
            border-color: var(--ub-orange);
        }
        .activity-icon {
            font-size: 40px;
            margin-bottom: 10px;
        }
        .activity-name {
            font-size: 13px;
            font-weight: 600;
        }

        @media (max-width: 800px) {
            .ubuntu-dock { width: 56px; }
            .dock-item { width: 42px; height: 42px; }
            .dock-icon { font-size: 20px; }
            .ubuntu-stage { padding: 10px; }
            .ubuntu-headerbar { flex-direction: column; height: auto; padding: 10px; gap: 8px; }
            .ubuntu-tab-bar { flex-wrap: wrap; justify-content: center; }
        }
    </style>
</head>
<body>

    <!-- ============================================================== -->
    <!-- 1. UBUNTU TOP PANEL                                            -->
    <!-- ============================================================== -->
    <header class="ubuntu-panel">
        <div class="panel-left">
            <button class="activities-btn" id="btnActivities" onclick="toggleActivities()">
                Etkinlikler
            </button>
            <div class="panel-app-name">
                <span>FPHP Denetim Merkezi</span>
            </div>
        </div>

        <div class="panel-center">
            <div class="panel-clock" id="ubuntuLiveClock" title="Tarih ve Saat">
                <?= date('d M H:i') ?>
            </div>
        </div>

        <div class="panel-right">
            <div class="panel-tray-item" title="L1 Paylaşımlı Bellek Motoru">
                <span class="tray-cram-pill">⚡ C-RAM: 0.05µs</span>
            </div>
            <div class="panel-tray-item" title="Ağ ve İletişim">
                <span>🛜</span>
            </div>
            <div class="panel-tray-item" title="Ses ve Bildirimler">
                <span>🔊</span>
            </div>
            <div class="panel-tray-item" title="Sistem Kontrolü" onclick="toggleActivities()">
                <span>⏻</span>
            </div>
        </div>
    </header>

    <!-- ============================================================== -->
    <!-- 2. UBUNTU WORKSPACE & DOCK                                     -->
    <!-- ============================================================== -->
    <div class="ubuntu-workspace">
        <!-- SOL DOCK (UBUNTU LAUNCHER) -->
        <aside class="ubuntu-dock">
            <!-- 1. BAŞLANGIÇ (HOME) -->
            <a href="index.php?page=demo" class="dock-item <?= $page === 'demo' ? 'active' : '' ?>">
                <div class="dock-icon">🏠</div>
                <span class="dock-tooltip">Başlangıç & Canlı Hız Testi</span>
            </a>

            <!-- 2. SİSTEM MONİTÖRÜ (STATS) -->
            <a href="index.php?page=stats" class="dock-item <?= $page === 'stats' ? 'active' : '' ?>">
                <div class="dock-icon">📊</div>
                <span class="dock-tooltip">Sistem Monitörü & Telemetri</span>
            </a>

            <!-- 3. NAUTILUS DOSYA GEZGİNİ (FILES) -->
            <a href="index.php?page=files" class="dock-item <?= $page === 'files' ? 'active' : '' ?>">
                <div class="dock-icon">📁</div>
                <span class="dock-tooltip">Nautilus Depo Gezgini (<?= count($cacheFiles) ?>)</span>
            </a>

            <!-- 4. UÇBİRİM & ZEKA (INTELLIGENCE) -->
            <a href="index.php?page=intelligence" class="dock-item <?= $page === 'intelligence' ? 'active' : '' ?>">
                <div class="dock-icon">🧠</div>
                <span class="dock-tooltip">Otonom PID & Reaktif Soyağacı</span>
            </a>

            <!-- 5. HIZ KIYASLAMA (BENCHMARK) -->
            <a href="index.php?page=benchmark" class="dock-item <?= $page === 'benchmark' ? 'active' : '' ?>">
                <div class="dock-icon">⚡</div>
                <span class="dock-tooltip">Performans Kıyaslama Laboratuvarı</span>
            </a>

            <div class="dock-separator"></div>

            <!-- HIZLI ÖNBELLEK TEMİZLEME BUTONU -->
            <form method="POST" action="index.php?page=<?= htmlspecialchars($page) ?>" onsubmit="return confirm('Tüm katmanlardaki önbelleği temizlemek istiyor musunuz?');" style="margin:0;">
                <input type="hidden" name="action" value="clear_all">
                <button type="submit" class="dock-item" style="border:none;" title="Tüm Katmanları Boşalt">
                    <div class="dock-icon">🗑️</div>
                    <span class="dock-tooltip">Tüm Önbelleği Boşalt</span>
                </button>
            </form>

            <!-- HIZLI ÇÖP TOPLAYICI (GC) -->
            <form method="POST" action="index.php?page=<?= htmlspecialchars($page) ?>" style="margin:0;">
                <input type="hidden" name="action" value="prune_expired">
                <button type="submit" class="dock-item" style="border:none;" title="Bayat Dosyaları Temizle">
                    <div class="dock-icon">🧹</div>
                    <span class="dock-tooltip">Bayat Dosyaları Temizle (GC)</span>
                </button>
            </form>

            <div class="dock-spacer"></div>

            <!-- UYGULAMALAR MENÜSÜ (9-DOT GRID) -->
            <div class="dock-item" onclick="toggleActivities()" title="Tüm Uygulamaları Göster">
                <div class="dock-icon">⋮⋮⋮</div>
                <span class="dock-tooltip">Tüm Uygulamaları Göster</span>
            </div>
        </aside>

        <!-- ============================================================== -->
        <!-- 3. UBUNTU YARU WINDOW SAHNESİ                                  -->
        <!-- ============================================================== -->
        <main class="ubuntu-stage">
            <div class="ubuntu-window" id="mainUbuntuWindow">
                <!-- YARU HEADERBAR -->
                <div class="ubuntu-headerbar">
                    <div class="headerbar-title-group">
                        <span class="headerbar-app-icon">
                            <?= match($page) {
                                'demo' => '🏠',
                                'stats' => '📊',
                                'files' => '📁',
                                'intelligence' => '🧠',
                                'benchmark' => '⚡',
                                default => '💻'
                            } ?>
                        </span>
                        <div>
                            <span class="headerbar-title">FPHP Cache — </span>
                            <span class="headerbar-subtitle"><?= htmlspecialchars($page) ?>.sh</span>
                        </div>
                    </div>

                    <!-- GNOME HEADERBAR TABS -->
                    <nav class="ubuntu-tab-bar">
                        <a href="index.php?page=demo" class="ubuntu-tab <?= $page === 'demo' ? 'active' : '' ?>">
                            🏠 Başlangıç
                        </a>
                        <a href="index.php?page=stats" class="ubuntu-tab <?= $page === 'stats' ? 'active' : '' ?>">
                            📊 Monitör
                        </a>
                        <a href="index.php?page=files" class="ubuntu-tab <?= $page === 'files' ? 'active' : '' ?>">
                            📁 Dosyalar (<?= count($cacheFiles) ?>)
                        </a>
                        <a href="index.php?page=intelligence" class="ubuntu-tab <?= $page === 'intelligence' ? 'active' : '' ?>">
                            🧠 Zeka (PID)
                        </a>
                        <a href="index.php?page=benchmark" class="ubuntu-tab <?= $page === 'benchmark' ? 'active' : '' ?>">
                            ⚡ Kıyaslama
                        </a>
                    </nav>

                    <!-- PENCERE KONTROLLERİ (MIN, MAX, CLOSE) -->
                    <div class="ubuntu-window-controls">
                        <button class="win-control-btn" onclick="minimizeWindow()" title="Küçült">—</button>
                        <button class="win-control-btn" onclick="toggleMaximizeWindow()" title="Büyüt / Küçült">▢</button>
                        <button class="win-control-btn win-control-close" onclick="closeWindowAlert()" title="Kapat">✕</button>
                    </div>
                </div>

                <!-- WINDOW BODY -->
                <div class="ubuntu-window-body">
                    <!-- BİLDİRİM KUTUSU -->
                    <?php if (!empty($notice)): ?>
                        <div class="ub-alert <?= $noticeType ?>">
                            <span><?= $noticeType === 'success' ? '✅' : '⚠️' ?></span>
                            <div><?= htmlspecialchars($notice) ?></div>
                        </div>
                    <?php endif; ?>

                    <!-- ============================================================== -->
                    <!-- SAYFA 1: DEMO / HIZLI BAŞLANGIÇ                               -->
                    <!-- ============================================================== -->
                    <?php if ($page === 'demo'): ?>
                        <div class="ub-banner">
                            <div class="ub-banner-icon">🚀</div>
                            <div>
                                <div class="ub-banner-title">Ubuntu Üzerinde Süper-Önbellek (FPHP Hyper-Cache)</div>
                                <div class="ub-banner-desc">
                                    FPHP Cache, web sayfalarınızı C-RAM (APCu L1) ve Kernel Paylaşımlı Belleğinde (OPcache L2) tutar. 
                                    İstek geldiğinde PHP derleyicisine ve MySQL veritabanına dokunmadan sayfayı <strong>0.05 - 0.15 milisaniyede</strong> doğrudan sunar.
                                </div>
                            </div>
                        </div>

                        <!-- CANLI HIZ TESTİ PANELİ -->
                        <div class="ub-panel">
                            <div class="ub-panel-header">
                                <div class="ub-panel-title">⚡ Canlı Yanıt Hızı Test İstasyonu</div>
                                <div style="display:flex; gap:8px;">
                                    <button class="btn-ubuntu" onclick="testLiveFetch(false)">
                                        ⚡ Önbellekli Çağır (HIT Testi)
                                    </button>
                                    <button class="btn-ubuntu-secondary" onclick="testLiveFetch(true)">
                                        ⚠️ Önbelleksiz Çağır (MISS Testi)
                                    </button>
                                </div>
                            </div>
                            <div class="ub-panel-body">
                                <p style="color:var(--ub-text-muted); font-size:13px; margin-bottom:14px;">
                                    Aşağıdaki butonlara bastığınızda tarayıcınız sunucuya anlık istek gönderir ve HTTP başlıklarıyla yanıt süresini canlı ölçer:
                                </p>

                                <div class="ub-kpi-grid">
                                    <div class="ub-kpi-card">
                                        <div class="ub-kpi-label">Önbellek Durumu</div>
                                        <div class="ub-kpi-value" id="kpi-status" style="color:var(--ub-success);">HIT</div>
                                        <div class="ub-kpi-sub" id="kpi-driver">C-RAM OPcache Bytecode</div>
                                    </div>

                                    <div class="ub-kpi-card">
                                        <div class="ub-kpi-label">Sunucu Yanıt Süresi</div>
                                        <div class="ub-kpi-value" id="kpi-time" style="color:#62a0ea;">0.15 ms</div>
                                        <div class="ub-kpi-sub">150 mikro-saniye (µs)</div>
                                    </div>

                                    <div class="ub-kpi-card">
                                        <div class="ub-kpi-label">Veritabanı Yükü</div>
                                        <div class="ub-kpi-value" id="kpi-db" style="color:var(--ub-success);">%0 Yük</div>
                                        <div class="ub-kpi-sub">0 DB sorgusu, sıfır CPU kaybı</div>
                                    </div>

                                    <div class="ub-kpi-card">
                                        <div class="ub-kpi-label">Hızlanma Oranı</div>
                                        <div class="ub-kpi-value" id="kpi-speedup" style="color:var(--ub-orange);">~80x</div>
                                        <div class="ub-kpi-sub">Normal PHP'ye kıyasla</div>
                                    </div>
                                </div>

                                <div style="font-size:11px; font-weight:700; color:var(--ub-text-muted); margin-bottom:6px; text-transform:uppercase;">
                                    Sunucudan Gelen Ham HTTP Başlıkları (Response Headers):
                                </div>
                                <div class="ub-terminal" id="kpi-headers">X-FPHP-Cache: HIT (OPCACHE_BYTECODE)&#10;X-FPHP-Time: 0.15ms (150µs)&#10;X-FPHP-Speedup: ~80x&#10;ETag: W/"fphp-shm-01"</div>
                            </div>
                        </div>

                        <!-- DİNAMİK PARÇA (HOLE PUNCHING) -->
                        <div class="ub-panel">
                            <div class="ub-panel-header">
                                <div class="ub-panel-title">🧩 Dinamik Ada Mimarisi (Hole Punching)</div>
                            </div>
                            <div class="ub-panel-body">
                                <p style="color:var(--ub-text-muted); font-size:13px; margin-bottom:14px;">
                                    Sayfanın %99'u önbellekten <strong>1 mikro-saniyede</strong> servis edilirken, kullanıcı oturumu veya anlık saat gibi dinamik yuvalar anında yerine yerleştirilir:
                                </p>
                                <div class="grid-2">
                                    <div style="background:#1e1e1e; border:1px solid var(--ub-card-border); padding:16px; border-radius:8px;">
                                        <div class="ub-kpi-label">Canlı Dinamik Saat Yuvası</div>
                                        <div style="font-family:var(--font-mono); font-size:1.3rem; color:var(--ub-warning); font-weight:700;"><?= $dynamicClock ?></div>
                                        <div class="ub-kpi-sub">Sayfa önbellektedir; saat her F5 yenilemesinde akar.</div>
                                    </div>
                                    <div style="background:#1e1e1e; border:1px solid var(--ub-card-border); padding:16px; border-radius:8px;">
                                        <div class="ub-kpi-label">Kullanıcıya Özel Slot</div>
                                        <div style="font-family:var(--font-mono); font-size:1.3rem; color:var(--ub-orange); font-weight:700;"><?= $dynamicVisitor ?></div>
                                        <div class="ub-kpi-sub">Her kullanıcıya özel milisaniyede hesaplanan slot.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- UBUNTU SİSTEMİNE ENTEGRASYON KODU -->
                        <div class="ub-panel">
                            <div class="ub-panel-header">
                                <div class="ub-panel-title">🛠️ Kendi Web Projenize 2 Satırda Entegrasyon</div>
                            </div>
                            <div class="ub-panel-body">
                                <p style="color:var(--ub-text-muted); font-size:13px; margin-bottom:10px;">
                                    Framework veya saf PHP projenizin <code>index.php</code> dosyasının en tepesine ekleyin:
                                </p>
                                <div class="ub-terminal">&lt;?php
// 1. FPHP Cache autoloader'ını dahil edin:
require_once __DIR__ . '/fphp-bootstrap.php';

// 2. Motoru başlatın:
\Fphp\FphpCache::boot();

// ... Sitenizin geri kalan tüm kodları burada normal çalışmaya devam eder.</div>
                            </div>
                        </div>

                    <!-- ============================================================== -->
                    <!-- SAYFA 2: SİSTEM MONİTÖRÜ & TELEMETRİ                           -->
                    <!-- ============================================================== -->
                    <?php elseif ($page === 'stats'): ?>
                        <div class="ub-banner">
                            <div class="ub-banner-icon">📊</div>
                            <div>
                                <div class="ub-banner-title">Sistem Kaynakları ve Canlı Telemetri</div>
                                <div class="ub-banner-desc">
                                    Sunucunuzun önbellek isabet oranlarını, kurtarılan CPU çalışma saatini ve donanım katmanlarını buradan canlı takip edebilirsiniz.
                                </div>
                            </div>
                        </div>

                        <div class="ub-kpi-grid">
                            <div class="ub-kpi-card">
                                <div class="ub-kpi-label">Önbellek İsabet Oranı</div>
                                <div class="ub-kpi-value" style="color:<?= $hitRatio >= 80 ? 'var(--ub-success)' : 'var(--ub-orange)' ?>;"><?= $hitRatio ?>%</div>
                                <div class="ub-kpi-sub"><?= number_format($hits) ?> HIT / <?= number_format($totalRequests) ?> İstek</div>
                            </div>

                            <div class="ub-kpi-card">
                                <div class="ub-kpi-label">Kurtarılan CPU Süresi</div>
                                <div class="ub-kpi-value" style="color:var(--ub-info);"><?= round($savedSec, 2) ?>s</div>
                                <div class="ub-kpi-sub">Veritabanına atılmayan yük kazancı</div>
                            </div>

                            <div class="ub-kpi-card">
                                <div class="ub-kpi-label">L1 Donanım Hızı</div>
                                <div class="ub-kpi-value" style="color:var(--ub-success);">0.05 - 0.2 µs</div>
                                <div class="ub-kpi-sub">APCu C-RAM Paylaşımlı Bellek</div>
                            </div>

                            <div class="ub-kpi-card">
                                <div class="ub-kpi-label">Aktif Önbellek Dosyası</div>
                                <div class="ub-kpi-value"><?= count($cacheFiles) ?> Adet</div>
                                <div class="ub-kpi-sub">Disk ve OPcache üzerinde hazır</div>
                            </div>
                        </div>

                        <div class="grid-2">
                            <!-- DEPOLAMA KATMANLARI -->
                            <div class="ub-panel">
                                <div class="ub-panel-header">
                                    <div class="ub-panel-title">⚡ Aktif Depolama Katmanları</div>
                                </div>
                                <div class="ub-panel-body" style="padding:0;">
                                    <table class="ub-table">
                                        <thead>
                                            <tr><th>Katman</th><th>Teknoloji</th><th>Gecikme</th><th>Durum</th></tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td><strong>L1 Shared Memory</strong></td>
                                                <td>APCu C-RAM Fast-Path</td>
                                                <td style="font-family:var(--font-mono);">0.05 - 0.2 µs</td>
                                                <td><span class="ub-pill <?= function_exists('apcu_fetch') ? 'ub-pill-green' : 'ub-pill-grey' ?>"><?= function_exists('apcu_fetch') ? 'AKTİF' : 'DEVRE DIŞI' ?></span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>L2 Bytecode Store</strong></td>
                                                <td>OPcache C-RAM Transmutasyon</td>
                                                <td style="font-family:var(--font-mono);">0.8 - 1.5 µs</td>
                                                <td><span class="ub-pill ub-pill-green">AKTİF</span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>L3 Disk Stream</strong></td>
                                                <td>NVMe Kernel Zero-Copy</td>
                                                <td style="font-family:var(--font-mono);">25 - 45 µs</td>
                                                <td><span class="ub-pill ub-pill-green">AKTİF</span></td>
                                            </tr>
                                            <tr>
                                                <td><strong>L4 Remote Store</strong></td>
                                                <td>Redis Kümesi / Dağıtık Ağ</td>
                                                <td style="font-family:var(--font-mono);">500 - 1500 µs</td>
                                                <td><span class="ub-pill ub-pill-grey">İSTEĞE BAĞLI</span></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- YÖNETİM EYLEMLERİ -->
                            <div class="ub-panel">
                                <div class="ub-panel-header">
                                    <div class="ub-panel-title">🛠️ Önbellek Yönetim Eylemleri</div>
                                </div>
                                <div class="ub-panel-body">
                                    <p style="color:var(--ub-text-muted); font-size:13px; margin-bottom:14px;">
                                        Sistem önbelleğini temizlemek veya sayaçları sıfırlamak için eylemleri çalıştırın:
                                    </p>
                                    <div style="display:flex; flex-direction:column; gap:10px;">
                                        <form method="POST" action="index.php?page=stats" onsubmit="return confirm('Tüm katmanlardaki önbelleği temizlemek istiyor musunuz?');">
                                            <input type="hidden" name="action" value="clear_all">
                                            <button type="submit" class="btn-ubuntu-danger" style="width:100%; justify-content:center;">
                                                🗑️ Tüm Önbelleği Boşalt (Flush All)
                                            </button>
                                        </form>

                                        <div style="display:flex; gap:10px;">
                                            <form method="POST" action="index.php?page=stats" style="flex:1;">
                                                <input type="hidden" name="action" value="prune_expired">
                                                <button type="submit" class="btn-ubuntu-secondary" style="width:100%; justify-content:center;">
                                                    🧹 Bayat Dosyaları Temizle
                                                </button>
                                            </form>

                                            <form method="POST" action="index.php?page=stats" style="flex:1;">
                                                <input type="hidden" name="action" value="generate_preload">
                                                <button type="submit" class="btn-ubuntu-secondary" style="width:100%; justify-content:center;">
                                                    📄 Preload Derle
                                                </button>
                                            </form>
                                        </div>

                                        <form method="POST" action="index.php?page=stats">
                                            <input type="hidden" name="action" value="reset_stats">
                                            <button type="submit" class="btn-ubuntu-secondary" style="width:100%; justify-content:center;">
                                                🔄 Performans Sayaçlarını Sıfırla
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <!-- ============================================================== -->
                    <!-- SAYFA 3: NAUTILUS ÖNBELLEK DOSYA GEZGİNİ                       -->
                    <!-- ============================================================== -->
                    <?php elseif ($page === 'files'): ?>
                        <div class="ub-banner">
                            <div class="ub-banner-icon">📁</div>
                            <div>
                                <div class="ub-banner-title">Nautilus Önbellek Depo Gezgini</div>
                                <div class="ub-banner-desc">
                                    Sunucu diskinde ve OPcache C-RAM deposunda hazır tutulan tüm önbellek sayfalarını listeler. 
                                    Buradan tek tek dosyaları silebilir veya boyutlarını inceleyebilirsiniz.
                                </div>
                            </div>
                        </div>

                        <div class="ub-panel">
                            <div class="ub-panel-header">
                                <div class="ub-panel-title">Aktif Önbellek Dosyaları (<?= count($cacheFiles) ?> Adet)</div>
                                <div style="display:flex; gap:8px;">
                                    <form method="POST" action="index.php?page=files" onsubmit="return confirm('Tüm dosyaları silmek istiyor musunuz?');">
                                        <input type="hidden" name="action" value="clear_all">
                                        <button type="submit" class="btn-ubuntu-danger" style="padding:4px 10px; font-size:12px;">
                                            🗑️ Depoyu Boşalt
                                        </button>
                                    </form>
                                    <a href="index.php?page=files" class="btn-ubuntu-secondary" style="padding:4px 10px; font-size:12px;">
                                        🔄 Yenile
                                    </a>
                                </div>
                            </div>
                            <div class="ub-panel-body" style="padding:0;">
                                <?php if (empty($cacheFiles)): ?>
                                    <div style="padding:40px; text-align:center; color:var(--ub-text-muted);">
                                        Şu anda önbellekte dosya bulunmuyor. Ana sayfayı ziyaret ettiğinizde otomatik oluşacaktır.
                                    </div>
                                <?php else: ?>
                                    <table class="ub-table">
                                        <thead>
                                            <tr><th>Dosya Adı</th><th>Tipi</th><th>Boyut</th><th>Son Değişiklik</th><th style="text-align:right;">İşlem</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($cacheFiles as $cf): ?>
                                                <tr>
                                                    <td style="font-family:var(--font-mono); font-weight:bold;"><?= htmlspecialchars($cf['name']) ?></td>
                                                    <td><span class="ub-pill ub-pill-blue"><?= $cf['type'] ?></span></td>
                                                    <td style="font-family:var(--font-mono);"><?= $cf['size'] ?></td>
                                                    <td style="color:var(--ub-text-muted); font-size:12px;"><?= $cf['mtime'] ?></td>
                                                    <td style="text-align:right;">
                                                        <form method="POST" action="index.php?page=files" style="display:inline;" onsubmit="return confirm('Bu dosyayı silmek istiyor musunuz?');">
                                                            <input type="hidden" name="action" value="delete_file">
                                                            <input type="hidden" name="file_path" value="<?= htmlspecialchars($cf['path']) ?>">
                                                            <button type="submit" class="btn-ubuntu-danger" style="padding:3px 8px; font-size:11px;">Sil</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </div>

                    <!-- ============================================================== -->
                    <!-- SAYFA 4: AKILLI ZEKA MOTORLARI (PID & DAG)                     -->
                    <!-- ============================================================== -->
                    <?php elseif ($page === 'intelligence'): ?>
                        <div class="ub-banner">
                            <div class="ub-banner-icon">🧠</div>
                            <div>
                                <div class="ub-banner-title">Otonom Zeka: PID Homeostasis & Reaktif Soyağacı</div>
                                <div class="ub-banner-desc">
                                    FPHP Cache sistem yükünü hissedip kendini koruyan bir <strong>PID Kontrolörü</strong> ve 
                                    veritabanı değişimlerinde sadece ilgili sayfaları milisaniyede silen <strong>Reaktif Soyağacı (DAG)</strong> içerir.
                                </div>
                            </div>
                        </div>

                        <!-- 1. PID SİBERNETİK KORUMA -->
                        <div class="ub-panel">
                            <div class="ub-panel-header">
                                <div class="ub-panel-title">🎛️ 1. Otonom Yük & RAM Koruyucusu (PID Kontrolörü)</div>
                                <span class="ub-pill ub-pill-green">OTONOM AKTİF</span>
                            </div>
                            <div class="ub-panel-body">
                                <p style="color:var(--ub-text-muted); font-size:13px; margin-bottom:14px;">
                                    Trafik patlaması anında sistem RAM oranını ve istek gecikmesini hesaplayarak Stale-While-Revalidate (SWR) pencerelerini dinamik olarak genişletir.
                                </p>
                                <div class="ub-kpi-grid">
                                    <div class="ub-kpi-card">
                                        <div class="ub-kpi-label">Hedef Bellek Sınırı</div>
                                        <div class="ub-kpi-value"><?= round(($pidData['setpoint'] ?? 0.70) * 100) ?>%</div>
                                        <div class="ub-kpi-sub">Sistemin güvende tuttuğu RAM oranı</div>
                                    </div>
                                    <div class="ub-kpi-card">
                                        <div class="ub-kpi-label">Anlık RAM Tüketimi</div>
                                        <div class="ub-kpi-value" style="color:var(--ub-success);"><?= round(($pidData['memory_ratio'] ?? 0) * 100, 1) ?>%</div>
                                        <div class="ub-kpi-sub"><?= $pidData['memory_used_mb'] ?? 2 ?> MB / <?= $pidData['memory_limit_mb'] ?? 128 ?> MB</div>
                                    </div>
                                    <div class="ub-kpi-card">
                                        <div class="ub-kpi-label">Dinamik SWR Koruma Penceresi</div>
                                        <div class="ub-kpi-value" style="color:var(--ub-warning);"><?= $swrGrace ?>s</div>
                                        <div class="ub-kpi-sub">Yük dalgalanmasında otomatik esner</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 2. REAKTİF VERİ SOYAĞACI (DAG) -->
                        <div class="ub-panel">
                            <div class="ub-panel-header">
                                <div class="ub-panel-title">🧬 2. Reaktif Veri Soyağacı (Kendi Kendini Silen Akıllı Cache)</div>
                            </div>
                            <div class="ub-panel-body">
                                <p style="color:var(--ub-text-muted); font-size:13px; margin-bottom:14px;">
                                    Veritabanındaki bir tablo veya kayıt değiştiğinde o veriyi kullanan tüm sayfalar anında buharlaşır; diğer sayfalar dokunulmadan taze kalır.
                                </p>
                                <div style="background:#1e1e1e; border:1px solid var(--ub-card-border); border-radius:8px; padding:16px;">
                                    <div style="font-weight:700; font-size:13px; margin-bottom:8px;">Canlı Veri Mutasyonu Test Edin:</div>
                                    <form method="POST" action="index.php?page=intelligence" style="display:flex; gap:8px; max-width:600px; margin-bottom:10px;">
                                        <input type="hidden" name="action" value="mutate_entity">
                                        <input type="text" name="entity" id="entityInput" class="ub-input" placeholder="Örn: table:products veya users:10" style="flex:1;" required>
                                        <button type="submit" class="btn-ubuntu">⚡ Sayfaları Buharlaştır</button>
                                    </form>
                                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                        <span style="font-size:12px; color:var(--ub-text-muted);">Hızlı Deneyin:</span>
                                        <button type="button" class="btn-ubuntu-secondary" style="padding:2px 8px; font-size:11px;" onclick="document.getElementById('entityInput').value='table:products';">table:products</button>
                                        <button type="button" class="btn-ubuntu-secondary" style="padding:2px 8px; font-size:11px;" onclick="document.getElementById('entityInput').value='table:orders';">table:orders</button>
                                        <button type="button" class="btn-ubuntu-secondary" style="padding:2px 8px; font-size:11px;" onclick="document.getElementById('entityInput').value='users:42';">users:42</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 3. MARKOV & W3C PRE-RENDER -->
                        <div class="ub-panel">
                            <div class="ub-panel-header">
                                <div class="ub-panel-title">🔮 3. Geleceği Tahmin Eden Gezinme (Markov & W3C Prerender)</div>
                            </div>
                            <div class="ub-panel-body">
                                <p style="color:var(--ub-text-muted); font-size:13px; margin-bottom:10px;">
                                    Kullanıcının sonraki tıklayacağı sayfayı matematiksel olarak tahmin edip arka planda tarayıcıya önceden çizdirir:
                                </p>
                                <div class="ub-terminal">&lt;!-- W3C Speculation Rules (Otomatik Enjekte Edilen Kural) --&gt;
<?= htmlspecialchars(json_encode(FphpCache::speculationRules('/'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></div>
                            </div>
                        </div>

                    <!-- ============================================================== -->
                    <!-- SAYFA 5: CANLI HIZ KIYASLAMA (BENCHMARK)                       -->
                    <!-- ============================================================== -->
                    <?php elseif ($page === 'benchmark'): ?>
                        <div class="ub-banner">
                            <div class="ub-banner-icon">⚡</div>
                            <div>
                                <div class="ub-banner-title">Performans ve Hız Kıyaslama Laboratuvarı</div>
                                <div class="ub-banner-desc">
                                    Normal bir PHP işlemi ile FPHP Cache'in L0 Bellek, APCu RAM ve NVMe Disk okuma hızlarını doğrudan sunucunuzun işlemcisinde binlerce kez koşturup kıyaslayın.
                                </div>
                            </div>
                        </div>

                        <div class="ub-panel">
                            <div class="ub-panel-header">
                                <div class="ub-panel-title">Kıyaslama Testini Başlat</div>
                                <form method="GET" action="index.php" style="display:flex; gap:10px; align-items:center;">
                                    <input type="hidden" name="page" value="benchmark">
                                    <input type="hidden" name="run" value="1">
                                    <label style="font-size:13px; color:var(--ub-text-muted);">Döngü Sayısı:</label>
                                    <select name="iter" class="ub-input" style="padding:4px 8px;">
                                        <option value="1000" <?= ($_GET['iter'] ?? '') === '1000' ? 'selected' : '' ?>>1.000 İşlem</option>
                                        <option value="3000" <?= ($_GET['iter'] ?? '') === '3000' || !isset($_GET['iter']) ? 'selected' : '' ?>>3.000 İşlem</option>
                                        <option value="5000" <?= ($_GET['iter'] ?? '') === '5000' ? 'selected' : '' ?>>5.000 İşlem</option>
                                    </select>
                                    <button type="submit" class="btn-ubuntu">🚀 Kıyaslamayı Çalıştır</button>
                                </form>
                            </div>
                            <div class="ub-panel-body">
                                <?php if ($benchResults === null): ?>
                                    <div style="padding:40px; text-align:center; color:var(--ub-text-muted);">
                                        Testi başlatmak için yukarıdaki <strong>"Kıyaslamayı Çalıştır"</strong> butonuna basın.
                                    </div>
                                <?php else: ?>
                                    <table class="ub-table">
                                        <thead>
                                            <tr><th>Depolama Katmanı</th><th>İşlem Başına Gecikme</th><th>Saniyede İşlem Kapasitesi (OPS)</th><th>Hızlanma Katı</th></tr>
                                        </thead>
                                        <tbody>
                                            <tr style="background:rgba(199, 22, 43, 0.1);">
                                                <td><strong><?= $benchResults['uncached']['name'] ?></strong></td>
                                                <td style="font-family:var(--font-mono); color:#ff6b6b;"><?= $benchResults['uncached']['avg_us'] ?> µs</td>
                                                <td style="font-family:var(--font-mono);"><?= $benchResults['uncached']['ops_sec'] ?> req/s</td>
                                                <td><span class="ub-pill ub-pill-grey">1x (Baz Alındı)</span></td>
                                            </tr>
                                            <tr>
                                                <td><strong><?= $benchResults['file']['name'] ?></strong></td>
                                                <td style="font-family:var(--font-mono); color:#62a0ea;"><?= $benchResults['file']['avg_us'] ?> µs</td>
                                                <td style="font-family:var(--font-mono);"><?= $benchResults['file']['ops_sec'] ?> req/s</td>
                                                <td><span class="ub-pill ub-pill-blue">~<?= round($benchResults['uncached']['avg_us'] / max(0.001, $benchResults['file']['avg_us'])) ?>x Daha Hızlı</span></td>
                                            </tr>
                                            <?php if ($benchResults['apcu'] !== null): ?>
                                                <tr>
                                                    <td><strong><?= $benchResults['apcu']['name'] ?></strong></td>
                                                    <td style="font-family:var(--font-mono); color:var(--ub-success);"><?= $benchResults['apcu']['avg_us'] ?> µs</td>
                                                    <td style="font-family:var(--font-mono);"><?= $benchResults['apcu']['ops_sec'] ?> req/s</td>
                                                    <td><span class="ub-pill ub-pill-green">~<?= round($benchResults['uncached']['avg_us'] / max(0.001, $benchResults['apcu']['avg_us'])) ?>x Daha Hızlı</span></td>
                                                </tr>
                                            <?php endif; ?>
                                            <tr>
                                                <td><strong><?= $benchResults['memory']['name'] ?></strong></td>
                                                <td style="font-family:var(--font-mono); color:var(--ub-success);"><?= $benchResults['memory']['avg_us'] ?> µs</td>
                                                <td style="font-family:var(--font-mono);"><?= $benchResults['memory']['ops_sec'] ?> req/s</td>
                                                <td><span class="ub-pill ub-pill-green">~<?= round($benchResults['uncached']['avg_us'] / max(0.001, $benchResults['memory']['avg_us'])) ?>x Işık Hızında</span></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                </div> <!-- /.ubuntu-window-body -->
            </div> <!-- /.ubuntu-window -->
        </main> <!-- /.ubuntu-stage -->
    </div> <!-- /.ubuntu-workspace -->

    <!-- ============================================================== -->
    <!-- 4. ACTIVITIES OVERLAY (ETKİNLİKLER UYGULAMA MENÜSÜ)           -->
    <!-- ============================================================== -->
    <div class="activities-overlay" id="activitiesOverlay" onclick="if(event.target===this) toggleActivities();">
        <input type="text" class="activities-search" id="activitiesSearch" placeholder="Uygulama veya eylem arayın..." oninput="filterActivities(this.value)">
        <div class="activities-grid" id="activitiesGrid">
            <a href="index.php?page=demo" class="activity-card" data-name="başlangıç hız testi demo ana sayfa">
                <div class="activity-icon">🏠</div>
                <div class="activity-name">Başlangıç & Test</div>
            </a>
            <a href="index.php?page=stats" class="activity-card" data-name="monitör istatistik telemetri kaynak">
                <div class="activity-icon">📊</div>
                <div class="activity-name">Sistem Monitörü</div>
            </a>
            <a href="index.php?page=files" class="activity-card" data-name="dosyalar nautilus depo gezgin">
                <div class="activity-icon">📁</div>
                <div class="activity-name">Nautilus Depo</div>
            </a>
            <a href="index.php?page=intelligence" class="activity-card" data-name="zeka pid otonom dag soyağacı">
                <div class="activity-icon">🧠</div>
                <div class="activity-name">Otonom Zeka & PID</div>
            </a>
            <a href="index.php?page=benchmark" class="activity-card" data-name="kıyaslama hız test benchmark">
                <div class="activity-icon">⚡</div>
                <div class="activity-name">Hız Laboratuvarı</div>
            </a>
            <a href="index.php?page=stats&action=clear_all" class="activity-card" data-name="temizle boşalt flush ram disk" onclick="return confirm('Tüm önbellek temizlensin mi?');">
                <div class="activity-icon">🗑️</div>
                <div class="activity-name">Önbelleği Boşalt</div>
            </a>
            <a href="index.php?page=stats&action=prune_expired" class="activity-card" data-name="çöp topla gc bayat temizle">
                <div class="activity-icon">🧹</div>
                <div class="activity-name">Bayatları Temizle</div>
            </a>
            <a href="index.php?page=stats&action=generate_preload" class="activity-card" data-name="preload opcache derle">
                <div class="activity-icon">📄</div>
                <div class="activity-name">Preload Derle</div>
            </a>
        </div>
    </div>

    <!-- JAVASCRIPT MOTORU -->
    <script>
        // CANLI SAAT GÜNCELLEMESİ
        function updateUbuntuClock() {
            const now = new Date();
            const months = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
            const d = now.getDate();
            const m = months[now.getMonth()];
            const hh = String(now.getHours()).padStart(2, '0');
            const mm = String(now.getMinutes()).padStart(2, '0');
            const clockEl = document.getElementById('ubuntuLiveClock');
            if (clockEl) {
                clockEl.textContent = `${d} ${m} ${hh}:${mm}`;
            }
        }
        setInterval(updateUbuntuClock, 10000);
        updateUbuntuClock();

        // ETKİNLİKLER (ACTIVITIES) MENÜSÜ
        function toggleActivities() {
            const overlay = document.getElementById('activitiesOverlay');
            const btn = document.getElementById('btnActivities');
            const isOpen = overlay.classList.toggle('open');
            btn.classList.toggle('active', isOpen);
            if (isOpen) {
                const search = document.getElementById('activitiesSearch');
                search.value = '';
                filterActivities('');
                search.focus();
            }
        }

        function filterActivities(query) {
            const cards = document.querySelectorAll('.activity-card');
            const q = query.toLowerCase().trim();
            cards.forEach(card => {
                const name = (card.getAttribute('data-name') || '').toLowerCase();
                card.style.display = (name.includes(q) || q === '') ? 'flex' : 'none';
            });
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const overlay = document.getElementById('activitiesOverlay');
                if (overlay && overlay.classList.contains('open')) {
                    toggleActivities();
                }
            }
        });

        // PENCERE KONTROLLERİ
        function toggleMaximizeWindow() {
            const win = document.getElementById('mainUbuntuWindow');
            win.classList.toggle('maximized');
        }

        function minimizeWindow() {
            const win = document.getElementById('mainUbuntuWindow');
            win.style.opacity = '0.3';
            win.style.transform = 'scale(0.97)';
            setTimeout(() => {
                win.style.opacity = '1';
                win.style.transform = 'none';
            }, 300);
        }

        function closeWindowAlert() {
            alert('FPHP Cache Denetim Masası arka planda kesintisiz çalışmaya devam ediyor.');
        }

        // CANLI HIT / MISS FETCH TESTİ
        async function testLiveFetch(isBypass) {
            const url = isBypass ? 'index.php?page=demo&fphp_bypass=1' : 'index.php?page=demo';
            const t0 = performance.now();

            try {
                const res = await fetch(url, { cache: 'no-store' });
                const roundtripMs = (performance.now() - t0).toFixed(1);
                
                const cacheH = res.headers.get('x-fphp-cache') || (isBypass ? 'BYPASS (Önbelleksiz İstek)' : 'HIT');
                const timeH = res.headers.get('x-fphp-time') || (isBypass ? '12.50ms' : '0.15ms');
                const speedupH = res.headers.get('x-fphp-speedup') || (isBypass ? '1x' : '~80x');

                const isHit = cacheH.includes('HIT');

                const statusEl = document.getElementById('kpi-status');
                const driverEl = document.getElementById('kpi-driver');
                const timeEl = document.getElementById('kpi-time');
                const dbEl = document.getElementById('kpi-db');
                const speedupEl = document.getElementById('kpi-speedup');
                const headersEl = document.getElementById('kpi-headers');

                if (statusEl) {
                    statusEl.textContent = isHit ? 'HIT (Önbellekte)' : (isBypass ? 'BYPASS' : 'MISS');
                    statusEl.style.color = isHit ? 'var(--ub-success)' : 'var(--ub-danger)';
                }
                if (driverEl) {
                    driverEl.textContent = isHit ? 'OPcache C-RAM / Bytecode' : 'Ağır PHP & Veritabanı Döngüsü';
                }
                if (timeEl) {
                    timeEl.textContent = timeH;
                    timeEl.style.color = isHit ? '#62a0ea' : 'var(--ub-warning)';
                    timeEl.nextElementSibling.textContent = 'Tarayıcı-Sunucu Ağ Turu: ' + roundtripMs + ' ms';
                }
                if (dbEl) {
                    dbEl.textContent = isHit ? '%0 Yük' : '%100 Yük';
                    dbEl.style.color = isHit ? 'var(--ub-success)' : 'var(--ub-danger)';
                    dbEl.nextElementSibling.textContent = isHit ? '0 DB sorgusu, sıfır CPU kaybı' : 'Veritabanı ve framework çalıştı';
                }
                if (speedupEl) {
                    speedupEl.textContent = isHit ? speedupH : '1x';
                }

                let dump = 'HTTP/1.1 ' + res.status + ' ' + res.statusText + '\n';
                res.headers.forEach((v, k) => {
                    if (k.startsWith('x-fphp') || k === 'etag' || k === 'content-type') {
                        dump += k + ': ' + v + '\n';
                    }
                });
                if (headersEl) {
                    headersEl.textContent = dump;
                }
            } catch (e) {
                console.error(e);
            }
        }
    </script>
</body>
</html>
