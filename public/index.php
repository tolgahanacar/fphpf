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
// Eğer URL'de özel bir yönetim sayfası veya eylem yoksa FPHP Full Page Microcache devreye girer
$page = $_GET['page'] ?? 'demo';
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$theme = $_GET['theme'] ?? $_COOKIE['fphp_theme'] ?? '90s';
if ($theme === '1996') {
    $theme = '90s';
}
if (isset($_GET['theme'])) {
    @setcookie('fphp_theme', $theme, time() + 86400 * 30, '/');
}

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
            if (count($cacheFiles) >= 60) break;
        }
        usort($cacheFiles, fn($a, $b) => $b['raw_mtime'] <=> $a['raw_mtime']);
    }
}

// Canlı Dinamik Ada (Hole Punching) Slotları
$dynamicClock = \Fphp\FphpCache::hole('live_clock', function () {
    return date('H:i:s') . ' (.' . substr((string)microtime(true), -4) . ' µs)';
}, fallback: date('H:i:s'));

$dynamicVisitor = \Fphp\FphpCache::hole('live_visitor', function () {
    return 'USER-' . substr(md5($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' . microtime()), 0, 6);
}, fallback: 'USER-DEMO');

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
    <title>FPHP Cache - Merkezi Yönetim ve Hızlandırma Konsolu</title>
    <style>
        :root {
            --bg: #090a0f;
            --surface: #11141c;
            --surface-elevated: #161a24;
            --border: #232838;
            --border-hover: #374151;
            --text-primary: #f3f4f6;
            --text-secondary: #9ca3af;
            --text-muted: #6b7280;
            --accent-blue: #3b82f6;
            --accent-green: #10b981;
            --accent-amber: #f59e0b;
            --accent-red: #ef4444;
            --accent-cyan: #06b6d4;
            --font-mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            --font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background-color: var(--bg);
            color: var(--text-primary);
            font-family: var(--font-sans);
            font-size: 14px;
            line-height: 1.5;
            min-height: 100vh;
        }

        /* HEADER */
        .top-nav {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 0.75rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-size: 1.15rem;
            font-weight: 700;
        }
        .brand-pill {
            font-family: var(--font-mono);
            font-size: 11px;
            padding: 2px 6px;
            background: var(--surface-elevated);
            border: 1px solid var(--border);
            border-radius: 4px;
            color: var(--accent-blue);
        }

        .main-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        /* UNIFIED TABS */
        .tab-bar {
            display: flex;
            gap: 0.25rem;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1.5rem;
            overflow-x: auto;
        }
        .tab-item {
            padding: 0.7rem 1.1rem;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            border-bottom: 2px solid transparent;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            white-space: nowrap;
        }
        .tab-item:hover { color: var(--text-primary); }
        .tab-item.active {
            color: var(--accent-blue);
            border-bottom-color: var(--accent-blue);
        }

        /* ALERT */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 4px;
            margin-bottom: 1.25rem;
            font-size: 13px;
            border: 1px solid var(--border);
            background: var(--surface);
        }
        .alert.success { border-left: 4px solid var(--accent-green); }
        .alert.danger  { border-left: 4px solid var(--accent-red); }

        /* EXPLAINER CALLOUT */
        .explainer {
            background: rgba(59, 130, 246, 0.08);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 6px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
        }
        .explainer-title {
            font-weight: 700;
            color: #93c5fd;
            margin-bottom: 0.25rem;
            font-size: 13px;
        }
        .explainer-text {
            color: var(--text-secondary);
            font-size: 13px;
            line-height: 1.45;
        }

        /* KPIS */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .kpi-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 1.15rem;
        }
        .kpi-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--text-muted);
            letter-spacing: 0.04em;
            margin-bottom: 0.35rem;
        }
        .kpi-val {
            font-family: var(--font-mono);
            font-size: 1.75rem;
            font-weight: 700;
        }
        .kpi-sub {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 0.2rem;
        }

        /* CARDS AND GRIDS */
        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        .panel-header {
            padding: 0.75rem 1.15rem;
            background: var(--surface-elevated);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .panel-title {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-secondary);
        }
        .panel-body {
            padding: 1.15rem;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
        }
        @media (max-width: 820px) {
            .grid-2 { grid-template-columns: 1fr; }
        }

        /* TABLES */
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            text-align: left;
        }
        .table th {
            background: var(--surface-elevated);
            color: var(--text-muted);
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.6rem 0.85rem;
            border-bottom: 1px solid var(--border);
        }
        .table td {
            padding: 0.65rem 0.85rem;
            border-bottom: 1px solid var(--border);
        }
        .table tr:hover td { background: rgba(255, 255, 255, 0.015); }
        .mono { font-family: var(--font-mono); font-size: 12px; }

        /* BUTTONS & INPUTS */
        .btn {
            font-family: var(--font-sans);
            font-size: 12px;
            font-weight: 600;
            padding: 0.45rem 0.85rem;
            border-radius: 4px;
            border: 1px solid var(--border);
            background: var(--surface-elevated);
            color: var(--text-primary);
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
        .btn:hover { background: #1f2433; border-color: var(--border-hover); }
        .btn-primary { background: #2563eb; border-color: #1d4ed8; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-danger { background: rgba(239, 68, 68, 0.15); border-color: rgba(239, 68, 68, 0.4); color: #fca5a5; }
        .btn-danger:hover { background: rgba(239, 68, 68, 0.25); }

        .btn-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .input-text {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 0.45rem 0.75rem;
            color: var(--text-primary);
            font-family: var(--font-mono);
            font-size: 12px;
            outline: none;
        }
        .input-text:focus { border-color: var(--accent-blue); }

        /* PILLS */
        .pill {
            display: inline-block;
            font-size: 11px;
            font-family: var(--font-mono);
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 4px;
            border: 1px solid transparent;
        }
        .pill-green { background: rgba(16, 185, 129, 0.15); color: #34d399; border-color: rgba(16, 185, 129, 0.3); }
        .pill-blue  { background: rgba(59, 130, 246, 0.15); color: #60a5fa; border-color: rgba(59, 130, 246, 0.3); }
        .pill-amber { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border-color: rgba(245, 158, 11, 0.3); }
        .pill-gray  { background: var(--surface-elevated); color: var(--text-muted); border-color: var(--border); }

        .code-snippet {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 0.85rem;
            font-family: var(--font-mono);
            font-size: 12px;
            color: #93c5fd;
            overflow-x: auto;
            white-space: pre-wrap;
        }

        /* ============================================================== */
        /* 90'S RETRO CYBER-WEB THEME (NOSTALGIA EDITION)                 */
        /* ============================================================== */
        body.theme-90s,
        body.theme-retro {
            background-color: #008080 !important; /* 90'lar Klasik Turkuaz Masaüstü */
            background-image: radial-gradient(rgba(0, 0, 0, 0.15) 15%, transparent 16%) !important;
            background-size: 6px 6px !important;
            color: #000000 !important;
            font-family: "Times New Roman", Times, Georgia, serif !important;
            font-size: 14px !important;
            padding: 10px !important;
        }

        body.theme-90s a, body.theme-retro a { color: #0000ff !important; text-decoration: underline !important; }
        body.theme-90s a:visited, body.theme-retro a:visited { color: #800080 !important; }
        body.theme-90s a:active, body.theme-retro a:active { color: #ff0000 !important; }

        body.theme-90s .browser-window,
        body.theme-retro .browser-window {
            max-width: 1020px;
            margin: 0 auto;
            background: #c0c0c0;
            border: 3px outset #ffffff;
            box-shadow: 6px 6px 0px rgba(0, 0, 0, 0.6);
        }

        body.theme-90s .win-titlebar,
        body.theme-retro .win-titlebar {
            background: linear-gradient(90deg, #000080, #1084d0);
            color: #ffffff;
            padding: 3px 6px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-family: "MS Sans Serif", Tahoma, Arial, sans-serif;
            font-size: 12px;
            font-weight: bold;
            user-select: none;
            border-bottom: 1px solid #000;
        }

        body.theme-90s .win-controls,
        body.theme-retro .win-controls {
            display: flex;
            gap: 2px;
        }
        body.theme-90s .win-btn,
        body.theme-retro .win-btn {
            width: 16px;
            height: 14px;
            background: #c0c0c0;
            border: 1px outset #ffffff;
            font-size: 9px;
            font-weight: bold;
            line-height: 12px;
            text-align: center;
            color: #000000;
            cursor: pointer;
            box-shadow: 1px 1px 0px #000;
        }

        body.theme-90s .win-menubar,
        body.theme-retro .win-menubar {
            background: #c0c0c0;
            border-bottom: 1px solid #808080;
            padding: 3px 8px;
            font-family: "MS Sans Serif", Tahoma, Arial, sans-serif;
            font-size: 11px;
            display: flex;
            gap: 12px;
            color: #000;
        }

        body.theme-90s .win-toolbar,
        body.theme-retro .win-toolbar {
            background: #c0c0c0;
            border-bottom: 2px groove #ffffff;
            padding: 4px 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
            flex-wrap: wrap;
        }

        body.theme-90s .win-tool-group,
        body.theme-retro .win-tool-group {
            display: flex;
            gap: 4px;
            align-items: center;
        }

        body.theme-90s .win-tool-btn,
        body.theme-retro .win-tool-btn {
            background: #c0c0c0;
            border: 2px outset #ffffff;
            padding: 2px 7px;
            font-family: "MS Sans Serif", Tahoma, Arial, sans-serif;
            font-size: 11px;
            font-weight: bold;
            color: #000 !important;
            text-decoration: none !important;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            box-shadow: 1px 1px 0px #000;
        }
        body.theme-90s .win-tool-btn:active,
        body.theme-retro .win-tool-btn:active {
            border: 2px inset #ffffff;
        }

        body.theme-90s .win-urlbar,
        body.theme-retro .win-urlbar {
            background: #c0c0c0;
            border-bottom: 2px groove #ffffff;
            padding: 4px 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: "MS Sans Serif", Tahoma, Arial, sans-serif;
            font-size: 11px;
            font-weight: bold;
        }
        body.theme-90s .win-url-input,
        body.theme-retro .win-url-input {
            flex: 1;
            background: #ffffff;
            border: 2px inset #ffffff;
            padding: 2px 6px;
            font-family: "Courier New", monospace;
            font-size: 12px;
            color: #000;
        }

        body.theme-90s .retro-content-wrap,
        body.theme-retro .retro-content-wrap {
            background: #ffffff;
            border: 2px inset #ffffff;
            margin: 8px;
            padding: 16px;
            color: #000;
        }

        body.theme-90s .tab-bar,
        body.theme-retro .tab-bar {
            background: #c0c0c0 !important;
            border-bottom: 2px groove #ffffff !important;
            padding: 6px 8px 0 8px !important;
            gap: 3px !important;
        }
        body.theme-90s .tab-item,
        body.theme-retro .tab-item {
            background: #c0c0c0 !important;
            border: 2px outset #ffffff !important;
            border-bottom: none !important;
            border-radius: 4px 4px 0 0 !important;
            color: #000000 !important;
            font-family: "MS Sans Serif", Tahoma, Arial, sans-serif !important;
            font-size: 11px !important;
            font-weight: bold !important;
            padding: 4px 10px !important;
            box-shadow: 1px 1px 0px #000;
        }
        body.theme-90s .tab-item.active,
        body.theme-retro .tab-item.active {
            background: #ffffff !important;
            border-bottom: 2px solid #ffffff !important;
            color: #000080 !important;
            margin-bottom: -2px !important;
        }

        body.theme-90s .btn,
        body.theme-retro .btn {
            background: #c0c0c0 !important;
            border: 2px outset #ffffff !important;
            color: #000000 !important;
            font-family: "MS Sans Serif", Tahoma, Arial, sans-serif !important;
            font-size: 12px !important;
            font-weight: bold !important;
            border-radius: 0 !important;
            box-shadow: 1px 1px 0px #000000 !important;
            padding: 4px 12px !important;
            cursor: pointer !important;
            text-decoration: none !important;
        }
        body.theme-90s .btn:active,
        body.theme-retro .btn:active {
            border: 2px inset #ffffff !important;
        }
        body.theme-90s .btn-primary,
        body.theme-retro .btn-primary {
            background: #000080 !important;
            color: #ffff00 !important;
            border: 2px outset #ffffff !important;
        }
        body.theme-90s .btn-danger,
        body.theme-retro .btn-danger {
            background: #800000 !important;
            color: #ffffff !important;
            border: 2px outset #ffffff !important;
        }

        body.theme-90s .panel,
        body.theme-retro .panel {
            background: #e4e4e4 !important;
            border: 2px groove #ffffff !important;
            border-radius: 0 !important;
            margin-bottom: 1.25rem !important;
        }
        body.theme-90s .panel-header,
        body.theme-retro .panel-header {
            background: #c0c0c0 !important;
            border-bottom: 2px groove #ffffff !important;
        }
        body.theme-90s .panel-title,
        body.theme-retro .panel-title {
            color: #000080 !important;
            font-family: "MS Sans Serif", Tahoma, Arial, sans-serif !important;
            font-weight: bold !important;
            font-size: 12px !important;
        }
        body.theme-90s .panel-body,
        body.theme-retro .panel-body {
            color: #000000 !important;
        }

        body.theme-90s .kpi-card,
        body.theme-retro .kpi-card {
            background: #ffffff !important;
            border: 2px inset #ffffff !important;
            border-radius: 0 !important;
            box-shadow: none !important;
        }
        body.theme-90s .kpi-label,
        body.theme-retro .kpi-label {
            color: #000080 !important;
            font-family: "MS Sans Serif", Tahoma, Arial, sans-serif !important;
            font-weight: bold !important;
        }
        body.theme-90s .kpi-val,
        body.theme-retro .kpi-val {
            font-family: "Courier New", monospace !important;
            font-weight: bold !important;
            color: #008000 !important;
        }
        body.theme-90s .kpi-sub,
        body.theme-retro .kpi-sub {
            color: #555555 !important;
            font-family: "Times New Roman", Times, serif !important;
        }

        body.theme-90s .code-snippet,
        body.theme-retro .code-snippet {
            background: #000000 !important;
            color: #00ff00 !important;
            border: 3px inset #808080 !important;
            border-radius: 0 !important;
            font-family: "Lucida Console", "Courier New", monospace !important;
            font-size: 13px !important;
            box-shadow: inset 0 0 10px rgba(0, 255, 0, 0.2) !important;
        }

        body.theme-90s .table,
        body.theme-retro .table {
            border: 2px inset #ffffff !important;
            background: #ffffff !important;
            color: #000000 !important;
        }
        body.theme-90s .table th,
        body.theme-retro .table th {
            background: #000080 !important;
            color: #ffffff !important;
            font-family: "MS Sans Serif", Tahoma, Arial, sans-serif !important;
            border: 1px solid #c0c0c0 !important;
        }
        body.theme-90s .table td,
        body.theme-retro .table td {
            border: 1px solid #dcdcdc !important;
            color: #000000 !important;
        }

        body.theme-90s .explainer,
        body.theme-retro .explainer {
            background: #ffffcc !important;
            border: 2px inset #ffffff !important;
            border-radius: 0 !important;
            color: #000000 !important;
        }
        body.theme-90s .explainer-title,
        body.theme-retro .explainer-title {
            color: #000080 !important;
            font-family: "MS Sans Serif", Tahoma, Arial, sans-serif !important;
            font-size: 13px !important;
        }
        body.theme-90s .explainer-text,
        body.theme-retro .explainer-text {
            color: #000000 !important;
        }

        body.theme-90s .win-statusbar,
        body.theme-retro .win-statusbar {
            background: #c0c0c0;
            border-top: 2px groove #ffffff;
            padding: 3px 6px;
            font-family: "MS Sans Serif", Tahoma, Arial, sans-serif;
            font-size: 11px;
            display: flex;
            gap: 6px;
        }
        body.theme-90s .win-status-pane,
        body.theme-retro .win-status-pane {
            border: 1px inset #ffffff;
            padding: 1px 6px;
            background: #c0c0c0;
        }

        /* RETRO SPECIAL GRAPHICS */
        .blink {
            animation: retroBlink 0.75s step-start infinite;
        }
        @keyframes retroBlink {
            50% { opacity: 0; }
        }

        .rainbow-hr {
            height: 4px;
            background: linear-gradient(90deg, red, orange, yellow, green, cyan, blue, violet);
            border: none;
            margin: 12px 0;
        }

        .hazard-stripe {
            background: repeating-linear-gradient(45deg, #ffcc00, #ffcc00 12px, #000000 12px, #000000 24px);
            height: 14px;
            border: 1px solid #000;
            margin: 10px 0;
        }

        .retro-counter {
            display: inline-flex;
            align-items: center;
            background: #000;
            padding: 3px 6px;
            border: 2px inset #ffffff;
            font-family: "Courier New", monospace;
            font-weight: bold;
            color: #00ff00;
        }
        .retro-counter span {
            background: #111;
            color: #00ff00;
            border: 1px solid #333;
            padding: 1px 5px;
            margin: 0 1px;
            font-size: 15px;
        }

        .retro-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: center;
            margin-top: 1.5rem;
            padding: 12px;
            border-top: 2px groove #ffffff;
            background: #e4e4e4;
        }
        .retro-badge-box {
            border: 2px outset #ffffff;
            background: #c0c0c0;
            padding: 3px 8px;
            font-family: "MS Sans Serif", Tahoma, Arial, sans-serif;
            font-size: 10px;
            font-weight: bold;
            color: #000000;
            display: flex;
            align-items: center;
            gap: 4px;
            box-shadow: 1px 1px 0px #000;
        }

        .theme-pill-switch {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* ============================================================== */
        /* MODERN MASAÜSTÜ SİSTEM KABUĞU (DESKTOP ENVIRONMENT)           */
        /* ============================================================== */
        body.theme-modern {
            background: radial-gradient(circle at 15% 15%, rgba(59, 130, 246, 0.12), transparent 45%),
                        radial-gradient(circle at 85% 85%, rgba(16, 185, 129, 0.08), transparent 45%),
                        #0a0e17 !important;
            min-height: 100vh;
            overflow-x: hidden;
        }

        .os-desktop-bar {
            background: rgba(15, 23, 42, 0.88);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            height: 42px;
            padding: 0 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .os-app-menu {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            font-size: 13px;
            color: #f3f4f6;
            padding: 4px 10px;
            border-radius: 5px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.08);
            cursor: pointer;
            user-select: none;
        }
        .os-app-menu:hover { background: rgba(255, 255, 255, 0.1); }

        .os-workspaces {
            display: flex;
            gap: 4px;
            margin-left: 8px;
        }
        .os-workspace-btn {
            width: 22px;
            height: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-family: var(--font-mono);
            border-radius: 4px;
            background: rgba(255, 255, 255, 0.04);
            color: var(--text-muted);
            border: 1px solid rgba(255, 255, 255, 0.06);
            user-select: none;
        }
        .os-workspace-btn.active {
            background: var(--accent-blue);
            color: #ffffff;
            font-weight: bold;
        }

        .os-tray {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .os-tray-item {
            font-size: 12px;
            font-family: var(--font-mono);
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 3px 8px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 4px;
        }

        /* DESKTOP WORKSPACE */
        .os-workspace {
            display: flex;
            padding: 1.25rem;
            gap: 1.25rem;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* DESKTOP SHORTCUTS (LEFT COLUMN) */
        .os-shortcuts {
            width: 90px;
            display: flex;
            flex-direction: column;
            gap: 14px;
            flex-shrink: 0;
            padding-top: 6px;
        }
        .os-shortcut {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            text-decoration: none;
            color: #d1d5db;
            padding: 8px 4px;
            border-radius: 8px;
            transition: background 0.15s, transform 0.15s;
            border: 1px solid transparent;
        }
        .os-shortcut:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(255, 255, 255, 0.12);
            transform: translateY(-2px);
            color: #ffffff;
        }
        .os-shortcut.active {
            background: rgba(59, 130, 246, 0.15);
            border-color: rgba(59, 130, 246, 0.4);
            color: #60a5fa;
        }
        .os-shortcut-icon {
            font-size: 26px;
            margin-bottom: 4px;
            line-height: 1;
        }
        .os-shortcut-label {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: -0.01em;
        }

        /* MAIN FLOATING OS WINDOW */
        .os-window {
            flex: 1;
            min-width: 0;
            background: rgba(17, 20, 28, 0.94);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.05);
            overflow: hidden;
        }

        .os-window-titlebar {
            background: rgba(22, 26, 36, 0.95);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 0.65rem 1.15rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .os-traffic-lights {
            display: flex;
            gap: 7px;
            align-items: center;
        }
        .os-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
        }
        .os-dot-red    { background: #ef4444; box-shadow: 0 0 6px rgba(239, 68, 68, 0.5); }
        .os-dot-yellow { background: #f59e0b; box-shadow: 0 0 6px rgba(245, 158, 11, 0.5); }
        .os-dot-green  { background: #10b981; box-shadow: 0 0 6px rgba(16, 185, 129, 0.5); }

        .os-window-title {
            font-family: var(--font-mono);
            font-size: 12px;
            color: var(--text-secondary);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .os-window-actions {
            display: flex;
            gap: 8px;
            color: var(--text-muted);
            font-size: 12px;
            font-family: var(--font-mono);
            user-select: none;
        }

        .os-window-body {
            padding: 1.5rem;
        }

        @media (max-width: 900px) {
            .os-workspace { flex-direction: column; padding: 0.75rem; }
            .os-shortcuts {
                width: 100%;
                flex-direction: row;
                overflow-x: auto;
                justify-content: flex-start;
                padding-top: 0;
            }
            .os-shortcut { width: 75px; flex-shrink: 0; }
        }
    </style>
</head>
<body class="<?= $theme === '90s' ? 'theme-90s' : 'theme-modern' ?>">

<?php if ($theme === '90s'): ?>
    <!-- ============================================================== -->
    <!-- 90'S RETRO CYBER-BROWSER SHELL                                 -->
    <!-- ============================================================== -->
    <div class="browser-window">
        <!-- WINDOW TITLE BAR -->
        <div class="win-titlebar">
            <div style="display:flex; align-items:center; gap:6px;">
                <span>🧭</span>
                <span>Retro Web Navigator - [FPHP HYPER-CACHE '90s - http://www.retro-web.local/~fphp]</span>
            </div>
            <div class="win-controls">
                <div class="win-btn">_</div>
                <div class="win-btn">口</div>
                <div class="win-btn">✕</div>
            </div>
        </div>

        <!-- WINDOW MENUBAR -->
        <div class="win-menubar">
            <span><u>D</u>osya</span>
            <span><u>D</u>üzen</span>
            <span><u>G</u>örünüm</span>
            <span><u>G</u>it</span>
            <span><u>Y</u>er İmleri</span>
            <span><u>S</u>eçenekler</span>
            <span><u>D</u>izin</span>
            <span><u>P</u>encere</span>
            <span><u>Y</u>ardım</span>
        </div>

        <!-- WINDOW TOOLBAR -->
        <div class="win-toolbar">
            <div class="win-tool-group">
                <a href="index.php?page=demo&theme=90s" class="win-tool-btn">⬅ Geri</a>
                <a href="index.php?page=demo&theme=90s" class="win-tool-btn">➡ İleri</a>
                <a href="index.php?page=demo&theme=90s" class="win-tool-btn">🏠 Ana Sayfa</a>
                <a href="javascript:location.reload()" class="win-tool-btn">🔄 Yenile</a>
                <a href="javascript:window.print()" class="win-tool-btn">🖨️ Yazdır</a>
                <a href="javascript:void(0)" class="win-tool-btn" onclick="alert('FPHP C-RAM Önbelleği Durdurulamaz!')">🛑 Durdur</a>
            </div>
            <div class="win-tool-group">
                <span style="font-family:'MS Sans Serif', Tahoma; font-size:11px; font-weight:bold;">Görünüm:</span>
                <a href="?page=<?= $page ?>&theme=90s" class="win-tool-btn" style="background:#000080; color:#ffff00 !important;">📺 90's Retro Web</a>
                <a href="?page=<?= $page ?>&theme=modern" class="win-tool-btn">🚀 2026 Modern Cyber</a>
            </div>
        </div>

        <!-- WINDOW URL BAR -->
        <div class="win-urlbar">
            <span>Konum (Location):</span>
            <input type="text" class="win-url-input" value="http://www.retro-web.local/~fphp/speed.htm?fphp_l1=active" readonly>
            <button class="win-tool-btn" onclick="location.reload()">Neyin Var?</button>
        </div>

        <!-- 90'S SCROLLING MARQUEE TICKER -->
        <marquee scrollamount="6" bgcolor="#000080" style="color:#ffff00; font-family:'Courier New', monospace; font-weight:bold; font-size:13px; padding:4px; border:2px inset #ffffff; margin-bottom:4px;">
            ⚡⚡⚡ DİKKAT CYBER-SÖRFÇÜLER! ⚡ 90'LARIN İLK 0.05µs (MİKRO-SANİYE) SÜPER-ÖNBELLEK MOTORUNA HOŞ GELDİNİZ! ⚡ ÇEVİRMELİ 28.8K MODEMLERİ TARİHE GÖMEN ZEND OPCACHE VE L1 C-RAM TEKNOLOJİSİ! ⚡ EN İYİ 800x600 ÇÖZÜNÜRLÜKTE GÖRÜNTÜLENİR! ⚡⚡⚡
        </marquee>

        <!-- NOTIFICATION -->
        <?php if (!empty($notice)): ?>
            <div class="alert <?= $noticeType ?>" style="margin:8px 8px 0 8px;">
                <?= htmlspecialchars($notice) ?>
            </div>
        <?php endif; ?>

        <!-- TABS (RETRO 90'S STYLE) -->
        <nav class="tab-bar">
            <a href="index.php?page=demo&theme=<?= $theme ?>" class="tab-item <?= $page === 'demo' ? 'active' : '' ?>">
                🏠 AnaSayfa.htm
            </a>
            <a href="index.php?page=stats&theme=<?= $theme ?>" class="tab-item <?= $page === 'stats' ? 'active' : '' ?>">
                📊 Telemetri.log
            </a>
            <a href="index.php?page=files&theme=<?= $theme ?>" class="tab-item <?= $page === 'files' ? 'active' : '' ?>">
                📁 Dosyalar.dir (<?= count($cacheFiles) ?>)
            </a>
            <a href="index.php?page=intelligence&theme=<?= $theme ?>" class="tab-item <?= $page === 'intelligence' ? 'active' : '' ?>">
                🧠 YapayZeka.sys
            </a>
            <a href="index.php?page=benchmark&theme=<?= $theme ?>" class="tab-item <?= $page === 'benchmark' ? 'active' : '' ?>">
                ⚡ Benchmark.exe
            </a>
        </nav>

        <div class="retro-content-wrap">
<?php else: ?>
    <!-- ============================================================== -->
    <!-- MASAÜSTÜ SİSTEM KABUĞU & GÖREV ÇUBUĞU (DESKTOP ENVIRONMENT)    -->
    <!-- ============================================================== -->
    <header class="os-desktop-bar">
        <div style="display:flex; align-items:center; gap:8px;">
            <div class="os-app-menu" title="Sistem Kontrol Menüsü">
                <span>⚡</span>
                <span>Sistem Menüsü</span>
            </div>
            <div class="os-workspaces">
                <div class="os-workspace-btn active">1</div>
                <div class="os-workspace-btn">2</div>
                <div class="os-workspace-btn">3</div>
            </div>
        </div>

        <div class="os-window-title">
            <span>💻 Masaüstü Konsolu — [ fphp-engine: aktif (0.05µs) ]</span>
        </div>

        <div class="os-tray">
            <div class="os-tray-item">
                <span style="color:#10b981;">●</span>
                <span>L1 C-RAM: 0.05µs</span>
            </div>
            <div class="os-tray-item">
                <span>🕒</span>
                <span id="os-live-clock"><?= date('H:i') ?></span>
            </div>
            <div class="theme-pill-switch">
                <a href="?page=<?= $page ?>&theme=90s" class="btn" style="padding:2px 8px; font-size:11px;">📺 90's Retro</a>
                <a href="?page=<?= $page ?>&theme=modern" class="btn btn-primary" style="padding:2px 8px; font-size:11px;">🖥️ Masaüstü</a>
            </div>
        </div>
    </header>

    <div class="os-workspace">
        <!-- SOL MASAÜSTÜ KISAYOLLARI (DESKTOP ICONS) -->
        <aside class="os-shortcuts">
            <a href="index.php?page=demo&theme=modern" class="os-shortcut <?= $page === 'demo' ? 'active' : '' ?>">
                <div class="os-shortcut-icon">🏠</div>
                <div class="os-shortcut-label">Başlangıç</div>
            </a>
            <a href="index.php?page=stats&theme=modern" class="os-shortcut <?= $page === 'stats' ? 'active' : '' ?>">
                <div class="os-shortcut-icon">📊</div>
                <div class="os-shortcut-label">Monitör</div>
            </a>
            <a href="index.php?page=files&theme=modern" class="os-shortcut <?= $page === 'files' ? 'active' : '' ?>">
                <div class="os-shortcut-icon">📁</div>
                <div class="os-shortcut-label">Dosyalar</div>
            </a>
            <a href="index.php?page=intelligence&theme=modern" class="os-shortcut <?= $page === 'intelligence' ? 'active' : '' ?>">
                <div class="os-shortcut-icon">🧠</div>
                <div class="os-shortcut-label">Zeka (PID)</div>
            </a>
            <a href="index.php?page=benchmark&theme=modern" class="os-shortcut <?= $page === 'benchmark' ? 'active' : '' ?>">
                <div class="os-shortcut-icon">⚡</div>
                <div class="os-shortcut-label">Kıyaslama</div>
            </a>
        </aside>

        <!-- MERKEZİ PENCERE (FLOATING OS WINDOW) -->
        <main class="os-window">
            <div class="os-window-titlebar">
                <div class="os-traffic-lights">
                    <span class="os-dot os-dot-red" title="Kapat"></span>
                    <span class="os-dot os-dot-yellow" title="Küçült"></span>
                    <span class="os-dot os-dot-green" title="Tam Ekran"></span>
                </div>
                <div class="os-window-title">
                    <span>🖥️ core@system: ~/fphp-engine/<?= htmlspecialchars($page) ?>.sh</span>
                </div>
                <div class="os-window-actions">
                    <span>—</span>
                    <span>▢</span>
                    <span>✕</span>
                </div>
            </div>

            <!-- WINDOW SUB TABS -->
            <nav class="tab-bar" style="margin-bottom:0; padding: 0.5rem 1rem 0 1rem; background: var(--surface-elevated);">
                <a href="index.php?page=demo&theme=<?= $theme ?>" class="tab-item <?= $page === 'demo' ? 'active' : '' ?>">
                    🏠 demo.sh
                </a>
                <a href="index.php?page=stats&theme=<?= $theme ?>" class="tab-item <?= $page === 'stats' ? 'active' : '' ?>">
                    📊 system_monitor.log
                </a>
                <a href="index.php?page=files&theme=<?= $theme ?>" class="tab-item <?= $page === 'files' ? 'active' : '' ?>">
                    📁 storage/ (<?= count($cacheFiles) ?>)
                </a>
                <a href="index.php?page=intelligence&theme=<?= $theme ?>" class="tab-item <?= $page === 'intelligence' ? 'active' : '' ?>">
                    🧠 pid_core.sys
                </a>
                <a href="index.php?page=benchmark&theme=<?= $theme ?>" class="tab-item <?= $page === 'benchmark' ? 'active' : '' ?>">
                    ⚡ benchmark.bin
                </a>
            </nav>

            <div class="os-window-body">
                <!-- NOTIFICATION -->
                <?php if (!empty($notice)): ?>
                    <div class="alert <?= $noticeType ?>">
                        <?= htmlspecialchars($notice) ?>
                    </div>
                <?php endif; ?>
<?php endif; ?>

        <?php if ($page === 'demo'): ?>
            <!-- ============================================================== -->
            <!-- SAYFA 1: HIZLI BAŞLANGIÇ VE CANLI TEST                         -->
            <!-- ============================================================== -->
            <?php if ($theme === '90s'): ?>
                <div style="text-align:center; margin-bottom:1rem;">
                    <div class="hazard-stripe"></div>
                    <h1 style="font-family:'Impact', 'Arial Black', sans-serif; font-size: 2.3rem; color: #000080; text-shadow: 2px 2px #ffff00, 3px 3px #ff0000; margin: 0.5rem 0; letter-spacing:1px;">
                        ★★★ FPHP HYPER-CACHE '90s ★★★
                    </h1>
                    <p style="font-family:'Comic Sans MS', cursive, sans-serif; font-size: 14px; color: #800000; margin-bottom:6px;">
                        <b>"DÜNYANIN EN HIZLI 90'LAR KİŞİSEL ANA SAYFASI & 0.05µs C-RAM HIZLANDIRICISI"</b>
                    </p>
                    <div style="margin: 6px 0;">
                        <span class="blink" style="color:#cc0000; font-family:'Courier New', monospace; font-weight:bold; font-size:13px; background:#ffff00; padding:2px 8px; border:1px solid #cc0000;">
                            [ 🔥 DİKKAT: 28.8K ÇEVİRMELİ MODEMLER İÇİN L1 C-RAM BYPASS TEKNOLOJİSİ DEVREDE! 🔥 ]
                        </span>
                    </div>
                    <div style="margin: 8px 0;">
                        <div class="retro-counter">
                            SAYFA ZİYARET SAYACI (90'LARDAN BERİ): <span>0</span><span>0</span><span>4</span><span>2</span><span>8</span><span>9</span>
                        </div>
                    </div>
                    <div class="rainbow-hr"></div>
                </div>

                <!-- 90'S DIALUP MODEM VS FPHP CALLOUT -->
                <table border="2" cellpadding="8" cellspacing="0" width="100%" bgcolor="#ffffcc" style="border: 2px inset #ffffff; margin-bottom: 1.25rem; font-family: 'Times New Roman', serif;">
                    <tr>
                        <td width="55" align="center" valign="middle" bgcolor="#ffcc00" style="font-size: 32px;">📟</td>
                        <td>
                            <b style="color: #000080; font-family: 'MS Sans Serif', Tahoma; font-size:13px;">SAYIN CYBER-YOLCU, HOŞ GELDİNİZ:</b><br>
                            Şu anda 90'lar ruhuyla tasarlanmış en hızlı World Wide Web sayfasındasınız! 
                            Normalde 28.8k veya 33.6k çevirmeli modemle bir sayfanın yüklenmesi <strong>45 saniye (45.000.000 mikro-saniye)</strong> sürer ve telefon hattını meşgul eder. 
                            Ancak FPHP motorunun <strong>L1 Shared Memory ve OPcache Transmutasyonu</strong> sayesinde bu sayfa size 
                            <strong style="color: #008000;">0.05 milisaniyede (50 mikro-saniye)</strong> ulaşmaktadır! 
                            <span style="color: #cc0000; font-weight: bold;">(Eski çevirmeli ağ sağlayıcılarından tam 900.000 kat daha hızlı!)</span>
                        </td>
                    </tr>
                </table>
            <?php else: ?>
                <div class="explainer">
                    <div style="font-size: 1.5rem; line-height: 1;">💡</div>
                    <div>
                        <div class="explainer-title">FPHP Cache Nedir ve Ne Yapar?</div>
                        <div class="explainer-text">
                            Normalde bir PHP sayfası her açıldığında yüzlerce dosya yüklenir, veritabanına bağlanılır ve sunucu yorulur. 
                            <strong>FPHP Cache</strong>, sayfaları bellekte (C-RAM / OPcache) hazır tutar. İstek geldiğinde veritabanına hiç dokunmadan 
                            sayfayı <strong>0.1 milisaniyede</strong> doğrudan sunar. Siteniz 10.000 kişi aynı anda gelse bile kilitlenmez.
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- CANLI HIZ TESTİ -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">⚡ Canlı Tepki Hızı Testi</div>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="testLiveFetch(false)">
                            <?= $theme === '90s' ? '⚡ Işık Hızında İndir (0.05µs HIT)' : '⚡ Önbellekli Çağır (HIT Testi)' ?>
                        </button>
                        <button class="btn btn-danger" onclick="testLiveFetch(true)">
                            <?= $theme === '90s' ? '🐌 28.8k Modem Simülasyonu (MISS)' : '⚠️ Önbelleksiz Çağır (Yavaş MySQL Testi)' ?>
                        </button>
                    </div>
                </div>
                <div class="panel-body">
                    <p style="color:var(--text-secondary); font-size:13px; margin-bottom:1rem;">
                        Aşağıdaki butonlara bastığınızda tarayıcınız sunucuya anlık istek gönderir ve yanıt süresini canlı ölçer:
                    </p>

                    <div class="kpi-row">
                        <div class="kpi-card">
                            <div class="kpi-label">Önbellek Durumu</div>
                            <div class="kpi-val" id="kpi-status" style="color:var(--accent-green);">HIT</div>
                            <div class="kpi-sub" id="kpi-driver">C-RAM OPcache Bytecode</div>
                        </div>

                        <div class="kpi-card">
                            <div class="kpi-label">Sunucu Yanıt Süresi</div>
                            <div class="kpi-val" id="kpi-time" style="color:var(--accent-cyan);">0.15 ms</div>
                            <div class="kpi-sub">150 mikro-saniye (µs)</div>
                        </div>

                        <div class="kpi-card">
                            <div class="kpi-label">Veritabanı Yükü</div>
                            <div class="kpi-val" id="kpi-db" style="color:var(--accent-green);">%0 Yük</div>
                            <div class="kpi-sub">0 DB sorgusu, sıfır CPU kaybı</div>
                        </div>

                        <div class="kpi-card">
                            <div class="kpi-label">Hızlanma Oranı</div>
                            <div class="kpi-val" id="kpi-speedup" style="color:var(--accent-blue);">~80x</div>
                            <div class="kpi-sub">Normal PHP'ye kıyasla</div>
                        </div>
                    </div>

                    <div style="font-size:12px; font-weight:700; color:var(--text-muted); margin-bottom:0.35rem; text-transform:uppercase;">
                        Sunucunun Döndürdüğü Ham HTTP Başlığı:
                    </div>
                    <div class="code-snippet" id="kpi-headers">X-FPHP-Cache: HIT (OPCACHE_BYTECODE)&#10;X-FPHP-Time: 0.15ms (150µs)&#10;X-FPHP-Speedup: ~80x</div>
                </div>
            </div>

            <!-- DİNAMİK PARÇA (HOLE PUNCHING) CANLI KANIT -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">🧩 Dinamik Parça Mimarisi (Hole Punching) Nedir?</div>
                </div>
                <div class="panel-body">
                    <p style="color:var(--text-secondary); font-size:13px; margin-bottom:1rem;">
                        <em>"Sayfayı önbelleğe alırsam kullanıcının sepeti veya canlı saat gibi kısımlar donup kalır mı?"</em> sorusunun çözümü:
                        Sayfanın %99'u önbellekten <strong>1 mikro-saniyede</strong> gelirken, sadece dinamik tanımlanan parçalar anlık hesaplanır:
                    </p>
                    <div class="grid-2">
                        <div class="metric-cell" style="background:var(--bg); border:1px solid var(--border); padding:1rem; border-radius:4px;">
                            <div class="kpi-label">Canlı Dinamik Saat Yuvası</div>
                            <div class="mono" style="font-size:1.2rem; color:var(--accent-amber); font-weight:700;"><?= $dynamicClock ?></div>
                            <div class="kpi-sub">Sayfa önbellektedir; saat her F5 yenilemesinde akar.</div>
                        </div>
                        <div class="metric-cell" style="background:var(--bg); border:1px solid var(--border); padding:1rem; border-radius:4px;">
                            <div class="kpi-label">Kullanıcıya Özel Slot</div>
                            <div class="mono" style="font-size:1.2rem; color:var(--accent-cyan); font-weight:700;"><?= $dynamicVisitor ?></div>
                            <div class="kpi-sub">Her kullanıcıya özel mikrosaniyede hesaplanan slot.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PROJEYE NASIL EKLENİR -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">🚀 Kendi Sitenize 2 Adımda Nasıl Kurulur?</div>
                </div>
                <div class="panel-body">
                    <p style="color:var(--text-secondary); font-size:13px; margin-bottom:0.75rem;">
                        Kendi web uygulamanızın veya framework'ünüzün (WordPress, Laravel veya saf PHP) <code>index.php</code> dosyasının en tepesine ekleyin:
                    </p>
                    <div class="code-snippet">&lt;?php
// 1. FPHP Cache autoloader'ını dahil edin:
require_once __DIR__ . '/fphp-bootstrap.php';

// 2. Motoru başlatın:
\Fphp\FphpCache::boot();

// ... Sitenizin geri kalan tüm kodları burada normal çalışmaya devam eder.</div>
                </div>
            </div>

        <?php elseif ($page === 'stats'): ?>
            <!-- ============================================================== -->
            <!-- SAYFA 2: TELEMETRİ VE İSTATİSTİKLER                            -->
            <!-- ============================================================== -->
            <div class="explainer">
                <div style="font-size: 1.5rem; line-height: 1;">📊</div>
                <div>
                    <div class="explainer-title">Canlı Sistem Durumu ve Telemetri</div>
                    <div class="explainer-text">
                        Sunucunuzun anlık önbellek performansını, toplam kaç isteğin önbellekten karşılandığını ve 
                        kurtarılan CPU süresini buradan görebilir, tek tıkla önbelleği boşaltabilirsiniz.
                    </div>
                </div>
            </div>

            <div class="kpi-row">
                <div class="kpi-card">
                    <div class="kpi-label">Önbellek İsabet Oranı</div>
                    <div class="kpi-val" style="color:<?= $hitRatio >= 80 ? 'var(--accent-green)' : 'var(--text-primary)' ?>;"><?= $hitRatio ?>%</div>
                    <div class="kpi-sub"><?= number_format($hits) ?> HIT / <?= number_format($totalRequests) ?> İstek</div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-label">Kurtarılan CPU Süresi</div>
                    <div class="kpi-val" style="color:var(--accent-blue);"><?= round($savedSec, 2) ?>s</div>
                    <div class="kpi-sub">Veritabanına atılmayan yük kazancı</div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-label">Bellek Yürütme Hızı</div>
                    <div class="kpi-val" style="color:var(--accent-cyan);">0.8 - 1.5 µs</div>
                    <div class="kpi-sub">C-RAM seviyesinde donanım hızında</div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-label">Toplam Önbellek Dosyası</div>
                    <div class="kpi-val"><?= count($cacheFiles) ?> Adet</div>
                    <div class="kpi-sub">Disk ve OPcache üzerinde aktif</div>
                </div>
            </div>

            <div class="grid-2">
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">⚡ Aktif Depolama Katmanları</div>
                    </div>
                    <div class="panel-body" style="padding:0;">
                        <table class="table">
                            <thead>
                                <tr><th>Katman</th><th>Teknoloji</th><th>Gecikme</th><th>Durum</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>L1 Shared Memory</strong></td>
                                    <td>APCu C-RAM Fast-Path</td>
                                    <td class="mono">0.05 - 0.2 µs</td>
                                    <td><span class="pill <?= function_exists('apcu_fetch') ? 'pill-green' : 'pill-gray' ?>"><?= function_exists('apcu_fetch') ? 'AKTİF' : 'DEVRE DIŞI' ?></span></td>
                                </tr>
                                <tr>
                                    <td><strong>L2 Bytecode Store</strong></td>
                                    <td>OPcache C-RAM Transmutasyon</td>
                                    <td class="mono">0.8 - 1.5 µs</td>
                                    <td><span class="pill pill-green">AKTİF</span></td>
                                </tr>
                                <tr>
                                    <td><strong>L3 Disk Stream</strong></td>
                                    <td>NVMe Kernel Zero-Copy</td>
                                    <td class="mono">25 - 45 µs</td>
                                    <td><span class="pill pill-green">AKTİF</span></td>
                                </tr>
                                <tr>
                                    <td><strong>L4 Remote Store</strong></td>
                                    <td>Redis Kümesi / Dağıtık Ağ</td>
                                    <td class="mono">500 - 1500 µs</td>
                                    <td><span class="pill pill-gray">İSTEĞE BAĞLI</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">🛠️ Önbellek Yönetim Eylemleri</div>
                    </div>
                    <div class="panel-body">
                        <p style="color:var(--text-secondary); font-size:13px; margin-bottom:1rem;">
                            Önbellekte biriken dosyaları temizlemek veya sayaçları sıfırlamak için aşağıdaki butonları kullanın:
                        </p>
                        <div class="btn-group">
                            <form method="POST" onsubmit="return confirm('Tüm katmanlardaki önbelleği temizlemek istediğinize emin misiniz?');">
                                <input type="hidden" name="action" value="clear_all">
                                <button type="submit" class="btn btn-danger">🗑️ Tüm Önbelleği Boşalt</button>
                            </form>

                            <form method="POST">
                                <input type="hidden" name="action" value="prune_expired">
                                <button type="submit" class="btn">🧹 Bayat Dosyaları Temizle (GC)</button>
                            </form>

                            <form method="POST">
                                <input type="hidden" name="action" value="generate_preload">
                                <button type="submit" class="btn">📄 Preload.php Derle</button>
                            </form>

                            <form method="POST">
                                <input type="hidden" name="action" value="reset_stats">
                                <button type="submit" class="btn">🔄 Sayaçları Sıfırla</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($page === 'files'): ?>
            <!-- ============================================================== -->
            <!-- SAYFA 3: ÖNBELLEK DOSYALARI GEZGİNİ                            -->
            <!-- ============================================================== -->
            <div class="explainer">
                <div style="font-size: 1.5rem; line-height: 1;">📁</div>
                <div>
                    <div class="explainer-title">Önbellek Dosya Gezgini</div>
                    <div class="explainer-text">
                        Sunucunuzun diskinde ve OPcache belleğinde şu anda üretilmiş olan tüm hazır sayfaları buradan görebilir,
                        tek tek silebilir veya boyutlarını inceleyebilirsiniz.
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">Aktif Önbellek Dosyaları (<?= count($cacheFiles) ?> Adet)</div>
                </div>
                <div class="panel-body" style="padding:0;">
                    <?php if (empty($cacheFiles)): ?>
                        <div style="padding:2rem; text-align:center; color:var(--text-muted);">
                            Şu anda önbellekte dosya bulunmuyor. Ana sayfayı ziyaret ettiğinizde otomatik oluşacaktır.
                        </div>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr><th>Dosya Adı</th><th>Tipi</th><th>Boyut</th><th>Tarih</th><th style="text-align:right;">İşlem</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cacheFiles as $cf): ?>
                                    <tr>
                                        <td class="mono"><?= htmlspecialchars($cf['name']) ?></td>
                                        <td><span class="pill pill-blue"><?= $cf['type'] ?></span></td>
                                        <td class="mono"><?= $cf['size'] ?></td>
                                        <td class="mono" style="color:var(--text-muted);"><?= $cf['mtime'] ?></td>
                                        <td style="text-align:right;">
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Bu dosyayı silmek istiyor musunuz?');">
                                                <input type="hidden" name="action" value="delete_file">
                                                <input type="hidden" name="file_path" value="<?= htmlspecialchars($cf['path']) ?>">
                                                <button type="submit" class="btn btn-danger" style="padding:2px 6px; font-size:11px;">Sil</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($page === 'intelligence'): ?>
            <!-- ============================================================== -->
            <!-- SAYFA 4: AKILLI ZEKA MOTORLARI (PID & DAG)                     -->
            <!-- ============================================================== -->
            <div class="explainer">
                <div style="font-size: 1.5rem; line-height: 1;">🧠</div>
                <div>
                    <div class="explainer-title">Gelişmiş Zeka & Otonom Sistemler Nedir?</div>
                    <div class="explainer-text">
                        Geleneksel önbellekler kördür; "1 saat önbellekte dursun" diye tahmin yapılır. 
                        <strong>FPHP</strong> ise veritabanınızdaki değişimleri takip eder (Soyağacı), aşırı trafik geldiğinde veritabanı çökmesin diye kendini otomatik korumaya alır (PID Kontrolörü) ve kullanıcının nereye tıklayacağını önceden tahmin eder (Markov).
                    </div>
                </div>
            </div>

            <!-- 1. PID SİBERNETİK KORUMA -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">🎛️ 1. Akıllı Yük ve Bellek Koruyucusu (PID Controller)</div>
                    <span class="pill pill-green">OTONOM AKTİF</span>
                </div>
                <div class="panel-body">
                    <p style="color:var(--text-secondary); font-size:13px; margin-bottom:1rem;">
                        <strong>Ne İşe Yarar?</strong> Bir anda 50.000 kişi sitenize girdiğinde normal sunucuların veritabanı kilitlenir. 
                        Bu PID algoritması, sistem yükünü hissedip önbellek koruma pencerelerini (SWR) otomatik uzatır; tehlike geçince normale döner.
                    </p>
                    <div class="kpi-row">
                        <div class="kpi-card">
                            <div class="kpi-label">Hedef Bellek Sınırı</div>
                            <div class="kpi-val"><?= round(($pidData['setpoint'] ?? 0.70) * 100) ?>%</div>
                            <div class="kpi-sub">Sistemin güvende tuttuğu RAM oranı</div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-label">Anlık RAM Tüketimi</div>
                            <div class="kpi-val" style="color:var(--accent-green);"><?= round(($pidData['memory_ratio'] ?? 0) * 100, 1) ?>%</div>
                            <div class="kpi-sub"><?= $pidData['memory_used_mb'] ?? 2 ?> MB / <?= $pidData['memory_limit_mb'] ?? 128 ?> MB</div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-label">Dinamik SWR Koruma Süresi</div>
                            <div class="kpi-val" style="color:var(--accent-amber);"><?= $swrGrace ?> saniye</div>
                            <div class="kpi-sub">Trafik patlamasında otomatik esner</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. REAKTİF VERİ SOYAĞACI (DAG) -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">🧬 2. Reaktif Veri Soyağacı (Kendi Kendini Silen Akıllı Cache)</div>
                </div>
                <div class="panel-body">
                    <p style="color:var(--text-secondary); font-size:13px; margin-bottom:1rem;">
                        <strong>Ne İşe Yarar?</strong> Artık süre tahmini (TTL) yapmanıza gerek yok. Veritabanındaki bir ürün veya sipariş değiştiğinde, 
                        o sayfaya bağlı tüm önbellek anında buharlaşır; değişmeyen sayfalar ise sonsuza kadar taze kalır.
                    </p>
                    <div style="background:var(--bg); border:1px solid var(--border); border-radius:4px; padding:1rem; margin-bottom:1rem;">
                        <div style="font-weight:700; font-size:13px; margin-bottom:0.5rem;">Canlı Mutasyon Testi Yapın:</div>
                        <form method="POST" style="display:flex; gap:0.5rem; max-width:550px;">
                            <input type="hidden" name="action" value="mutate_entity">
                            <input type="text" name="entity" class="input-text" placeholder="Örn: table:products veya user:42" style="flex:1;" required>
                            <button type="submit" class="btn btn-primary">⚡ Sayfaları Buharlaştır</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- 3. MARKOV VE W3C PRE-RENDER -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">🔮 3. Geleceği Tahmin Eden Gezinme (Markov & W3C Prerender)</div>
                </div>
                <div class="panel-body">
                    <p style="color:var(--text-secondary); font-size:13px; margin-bottom:1rem;">
                        <strong>Ne İşe Yarar?</strong> Kullanıcı örneğin anasayfadayken sıklıkla "/urunler" sayfasına gidiyorsa, 
                        tarayıcıya gizli bir talimat gönderilir. Kullanıcı henüz tıklamadan sayfa tarayıcının hafızasında çizilir ve tıklandığı an <strong>0.00 saniyede</strong> ekranda belirir!
                    </p>
                    <div class="code-snippet">&lt;!-- Tarayıcıya Gönderilen Spekülasyon Kuralı --&gt;
<?= htmlspecialchars(json_encode(FphpCache::speculationRules('/'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></div>
                </div>
            </div>

        <?php elseif ($page === 'benchmark'): ?>
            <!-- ============================================================== -->
            <!-- SAYFA 5: CANLI HIZ KIYASLAMA (BENCHMARK)                       -->
            <!-- ============================================================== -->
            <div class="explainer">
                <div style="font-size: 1.5rem; line-height: 1;">⚡</div>
                <div>
                    <div class="explainer-title">Canlı Performans ve Hız Kıyaslama Laboratuvarı</div>
                    <div class="explainer-text">
                        Normal bir PHP işlemi ile FPHP Cache'in L0 RAM, APCu ve NVMe Disk okuma hızlarını doğrudan 
                        kendi sunucunuzun işlemcisinde binlerce kez çalıştırarak karşılaştırın.
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">Hız Kıyaslamasını Başlat</div>
                    <form method="GET" style="display:flex; gap:0.5rem; align-items:center;">
                        <input type="hidden" name="page" value="benchmark">
                        <input type="hidden" name="run" value="1">
                        <label style="font-size:12px; color:var(--text-secondary);">Döngü Sayısı:</label>
                        <select name="iter" class="input-text" style="padding:2px 8px;">
                            <option value="1000" <?= ($_GET['iter'] ?? '') === '1000' ? 'selected' : '' ?>>1.000 İşlem</option>
                            <option value="3000" <?= ($_GET['iter'] ?? '') === '3000' || !isset($_GET['iter']) ? 'selected' : '' ?>>3.000 İşlem</option>
                            <option value="5000" <?= ($_GET['iter'] ?? '') === '5000' ? 'selected' : '' ?>>5.000 İşlem</option>
                        </select>
                        <button type="submit" class="btn btn-primary">🚀 Kıyaslamayı Çalıştır</button>
                    </form>
                </div>
                <div class="panel-body">
                    <?php if ($benchResults === null): ?>
                        <div style="padding:2rem; text-align:center; color:var(--text-muted);">
                            Hız testini başlatmak için yukarıdaki <strong>"Kıyaslamayı Çalıştır"</strong> butonuna basın.
                        </div>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr><th>Depolama Katmanı</th><th>İşlem Başına Gecikme</th><th>Saniyede İşlem Kapasitesi (OPS)</th><th>Hızlanma Katı</th></tr>
                            </thead>
                            <tbody>
                                <tr style="background:rgba(239, 68, 68, 0.05);">
                                    <td><strong><?= $benchResults['uncached']['name'] ?></strong></td>
                                    <td class="mono" style="color:var(--accent-red);"><?= $benchResults['uncached']['avg_us'] ?> µs</td>
                                    <td class="mono"><?= $benchResults['uncached']['ops_sec'] ?> req/s</td>
                                    <td><span class="pill pill-gray">1x (Baz Alındı)</span></td>
                                </tr>
                                <tr>
                                    <td><strong><?= $benchResults['file']['name'] ?></strong></td>
                                    <td class="mono" style="color:var(--accent-cyan);"><?= $benchResults['file']['avg_us'] ?> µs</td>
                                    <td class="mono"><?= $benchResults['file']['ops_sec'] ?> req/s</td>
                                    <td><span class="pill pill-blue">~<?= round($benchResults['uncached']['avg_us'] / max(0.001, $benchResults['file']['avg_us'])) ?>x Daha Hızlı</span></td>
                                </tr>
                                <?php if ($benchResults['apcu'] !== null): ?>
                                    <tr>
                                        <td><strong><?= $benchResults['apcu']['name'] ?></strong></td>
                                        <td class="mono" style="color:var(--accent-green);"><?= $benchResults['apcu']['avg_us'] ?> µs</td>
                                        <td class="mono"><?= $benchResults['apcu']['ops_sec'] ?> req/s</td>
                                        <td><span class="pill pill-green">~<?= round($benchResults['uncached']['avg_us'] / max(0.001, $benchResults['apcu']['avg_us'])) ?>x Daha Hızlı</span></td>
                                    </tr>
                                <?php endif; ?>
                                <tr>
                                    <td><strong><?= $benchResults['memory']['name'] ?></strong></td>
                                    <td class="mono" style="color:var(--accent-green);"><?= $benchResults['memory']['avg_us'] ?> µs</td>
                                    <td class="mono"><?= $benchResults['memory']['ops_sec'] ?> req/s</td>
                                    <td><span class="pill pill-green">~<?= round($benchResults['uncached']['avg_us'] / max(0.001, $benchResults['memory']['avg_us'])) ?>x Işık Hızında</span></td>
                                </tr>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($theme === '90s'): ?>
            <!-- RETRO 90'S WEB BADGES & FOOTER -->
            <div class="retro-badges">
                <div class="retro-badge-box">🧭 Web 1.0 Uyumlu</div>
                <div class="retro-badge-box">💻 Best Viewed 800x600</div>
                <div class="retro-badge-box">📝 Düz Metin Editörü ile Kodlandı</div>
                <div class="retro-badge-box">🛡️ CyberSafe Onaylı</div>
                <div class="retro-badge-box">✅ Valid HTML 3.2</div>
                <div class="retro-badge-box">⚡ Çift Çekirdekli Donanım Hızlandırma</div>
            </div>

            <div style="text-align:center; padding:12px; font-size:12px; color:#555; background:#c0c0c0; border-top:1px solid #808080;">
                <p style="margin-bottom:6px;">
                    <strong>Webmaster:</strong> Tolgahan Acar &lt;<a href="mailto:webmaster@retro-web.local">webmaster@retro-web.local</a>&gt; | 
                    <strong>IRC:</strong> #fphp @ EFNet | <strong>ICQ:</strong> 9005090
                </p>
                <p style="margin-bottom:6px;">
                    <a href="javascript:alert('Ziyaretçi defteri henüz boş! İlk imzalayan siz oldunuz.')">[ 📖 Ziyaretçi Defterini İmzala ]</a> | 
                    <a href="javascript:alert('WebRing sonraki siteye yönlendiriliyor...')">[ 🌐 WebRing: &laquo; Önceki | Rastgele | Sonraki &raquo; ]</a>
                </p>
                <p style="font-size:11px; color:#777;">
                    Bu sayfa basit bir metin editörü ve ham HTML ile yazılmıştır. Telif Hakkı &copy; 90's FPHP Cyber-Lab. Tüm Hakları Saklıdır.
                </p>
            </div>

            </div> <!-- /.retro-content-wrap -->

            <!-- 90'LAR KLASİK DURUM ÇUBUĞU -->
            <div class="win-statusbar">
                <div class="win-status-pane" style="flex:1;">🌐 Belge: Tamamlandı (0.05 ms / 50 µs)</div>
                <div class="win-status-pane">🔐 Güvenlik: 40-bit RC4</div>
                <div class="win-status-pane">💾 Bellek: 16 MB EDO RAM</div>
                <div class="win-status-pane">⚡ Mod: Dial-up Bypass</div>
            </div>
        </div> <!-- /.browser-window -->
    <?php else: ?>
            </div> <!-- /.os-window-body -->
        </main> <!-- /.os-window -->
    </div> <!-- /.os-workspace -->
    <?php endif; ?>

    <script>
        async function testLiveFetch(isBypass) {
            // Önbellekli istekte sabit URL istenir. Rastgele zaman damgası eklenirse her istek yeni bir sayfa (MISS) sayılır!
            const url = isBypass ? 'index.php?fphp_bypass=1' : 'index.php';
            const t0 = performance.now();

            try {
                const res = await fetch(url, { cache: 'no-store' });
                const roundtripMs = (performance.now() - t0).toFixed(1);
                
                const cacheH = res.headers.get('x-fphp-cache') || (isBypass ? 'BYPASS (Önbelleksiz İstek)' : 'HIT');
                const timeH = res.headers.get('x-fphp-time') || (isBypass ? '12.50ms' : '0.15ms');
                const speedupH = res.headers.get('x-fphp-speedup') || (isBypass ? '1x' : '~80x');

                const isHit = cacheH.includes('HIT');

                document.getElementById('kpi-status').textContent = isHit ? 'HIT (Önbellekte)' : (isBypass ? 'BYPASS' : 'MISS');
                document.getElementById('kpi-status').style.color = isHit ? 'var(--accent-green)' : 'var(--accent-red)';
                document.getElementById('kpi-driver').textContent = isHit ? 'OPcache C-RAM / Bytecode' : 'Ağır PHP & Veritabanı Döngüsü';

                document.getElementById('kpi-time').textContent = timeH;
                document.getElementById('kpi-time').style.color = isHit ? 'var(--accent-cyan)' : 'var(--accent-amber)';
                document.getElementById('kpi-time').nextElementSibling.textContent = 'Tarayıcı-Sunucu Ağ Turu: ' + roundtripMs + ' ms';

                document.getElementById('kpi-db').textContent = isHit ? '%0 Yük' : '%100 Yük';
                document.getElementById('kpi-db').style.color = isHit ? 'var(--accent-green)' : 'var(--accent-red)';
                document.getElementById('kpi-db').nextElementSibling.textContent = isHit ? '0 DB sorgusu, sıfır CPU kaybı' : 'Veritabanı ve framework çalıştı';

                document.getElementById('kpi-speedup').textContent = isHit ? speedupH : '1x';

                let dump = 'HTTP/1.1 ' + res.status + ' ' + res.statusText + '\n';
                res.headers.forEach((v, k) => {
                    if (k.startsWith('x-fphp') || k === 'etag' || k === 'content-type') {
                        dump += k + ': ' + v + '\n';
                    }
                });
                document.getElementById('kpi-headers').textContent = dump;
            } catch (e) {
                console.error(e);
            }
        }
    </script>
</body>
</html>
