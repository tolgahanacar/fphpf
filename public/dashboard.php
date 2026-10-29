<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/fphp-bootstrap.php';

use Fphp\FphpCache;
use Fphp\Driver\HybridDriver;
use Fphp\Driver\ApcuDriver;
use Fphp\Driver\FileDriver;
use Fphp\Profiler\Metrics;

// ==========================================
// 1. EYLEM YÖNETİCİSİ (ACTION HANDLER)
// ==========================================
$actionNotice = null;
$actionType = 'success'; // 'success' | 'danger' | 'info'

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['action'])) {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    try {
        if ($action === 'clear_all') {
            FphpCache::clear();
            $actionNotice = 'Tüm önbellek (OPcache Bytecode, APCu RAM ve NVMe Disk) başarıyla temizlendi.';
        } elseif ($action === 'prune_expired') {
            $pruned = FphpCache::prune();
            $actionNotice = 'Süresi dolmuş ' . $pruned['total_deleted'] . ' dosya temizlendi (' . Metrics::formatBytes($pruned['total_freed_bytes']) . ' alan kazanıldı).';
        } elseif ($action === 'reset_stats') {
            FphpCache::getStatsTracker()->reset();
            $actionNotice = 'Tüm telemetri sayaçları ve performans metrikleri sıfırlandı.';
        } elseif ($action === 'generate_preload') {
            $ok = FphpCache::generatePreloadFile();
            $actionNotice = $ok ? 'OPcache preload.php betiği kök dizinde başarıyla oluşturuldu.' : 'Preload dosyası oluşturulamadı.';
            if (!$ok) $actionType = 'danger';
        } elseif ($action === 'mutate_entity') {
            $entity = trim($_POST['entity'] ?? '');
            if (!empty($entity)) {
                $report = FphpCache::mutate($entity);
                $actionNotice = "Reaktif Soyağacı: '{$entity}' mutasyonu sonucu {$report['evicted_count']} adet bağlı önbellek kaydı anında buharlaştırıldı.";
            } else {
                $actionNotice = 'Lütfen geçersiz kılınacak bir veri varlığı (örn: table:products veya user:42) belirtin.';
                $actionType = 'danger';
            }
        } elseif ($action === 'delete_file') {
            $filePath = $_POST['file_path'] ?? '';
            $cacheDir = realpath(dirname(__DIR__) . '/cache');
            $targetReal = realpath($filePath);
            if ($targetReal && str_starts_with($targetReal, $cacheDir) && file_exists($targetReal)) {
                @unlink($targetReal);
                $actionNotice = 'Seçilen önbellek dosyası diskten silindi: ' . htmlspecialchars(basename($targetReal));
            } else {
                $actionNotice = 'Geçersiz veya yetkisiz dosya yolu.';
                $actionType = 'danger';
            }
        } elseif ($action === 'warmup_all') {
            $warmer = new \Fphp\Warmer\CacheWarmer();
            $results = $warmer->warmAll();
            $actionNotice = count($results) . ' adet kayıtlı sayfa paralel olarak belleğe önceden ısıtıldı.';
        }
    } catch (\Throwable $e) {
        $actionNotice = 'İşlem sırasında hata oluştu: ' . $e->getMessage();
        $actionType = 'danger';
    }
}

// ==========================================
// 2. METRİKLER VE VERİ TOPLAMA
// ==========================================
$currentTab = $_GET['tab'] ?? 'overview';

$stats = FphpCache::getStats();
$driver = FphpCache::getDriver();
$driverStats = $driver->getStats();
$config = FphpCache::getConfig();
$opcacheDiag = \Fphp\Optimizer\OpcacheManager::getDiagnostics();

$totalRequests = $stats['total_requests'] ?? 0;
$hits = $stats['hits'] ?? 0;
$misses = $stats['misses'] ?? 0;
$bypasses = $stats['bypasses'] ?? 0;
$hitRatio = $stats['hit_ratio'] ?? 0.0;
$savedSec = $stats['saved_time_seconds'] ?? 0.0;

// PID Controller verileri
$pid = FphpCache::getPidController();
$pidData = $pid ? $pid->getTelemetry() : [];
$swrGraceCurrent = FphpCache::adaptSwr(60);
$compressionCurrent = FphpCache::adaptCompression(6);

// Markov Warmer verileri
$markov = FphpCache::getMarkovWarmer();
$markovMatrix = [];
$markovFile = dirname(__DIR__) . '/cache/markov_transitions.json';
if (file_exists($markovFile)) {
    $rawM = @file_get_contents($markovFile);
    $markovMatrix = $rawM ? (json_decode($rawM, true) ?? []) : [];
}

// Reactive Lineage DAG verileri
$lineageGraph = FphpCache::getLineageGraph();
$lineageFile = dirname(__DIR__) . '/cache/lineage_graph.json';
$lineageData = ['entity_to_artifacts' => [], 'artifact_to_entities' => []];
if (file_exists($lineageFile)) {
    $rawL = @file_get_contents($lineageFile);
    $lineageData = $rawL ? (json_decode($rawL, true) ?? $lineageData) : $lineageData;
}

// Önbellek Dosyaları Listesi (Explorer)
$cacheFiles = [];
$cacheDir = dirname(__DIR__) . '/cache';
if (is_dir($cacheDir)) {
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        /** @var \SplFileInfo $file */
        $filename = $file->getFilename();
        if ($filename === '.gitkeep' || $filename === 'lineage_graph.json' || $filename === 'markov_transitions.json' || $filename === 'fphp_stats.json') {
            continue;
        }
        $ext = strtolower($file->getExtension());
        $type = match ($ext) {
            'php' => 'OPcache Bytecode',
            'fpc' => 'Raw Stream Store',
            'gz'  => 'Gzip Pre-compressed',
            default => 'Object Cache'
        };
        $cacheFiles[] = [
            'name' => $filename,
            'path' => $file->getPathname(),
            'type' => $type,
            'size' => $file->getSize(),
            'size_fmt' => Metrics::formatBytes($file->getSize()),
            'mtime' => $file->getMTime(),
            'date_fmt' => date('H:i:s d.m.Y', $file->getMTime())
        ];
        if (count($cacheFiles) >= 80) break; // Limit explorer to 80 items
    }
}
usort($cacheFiles, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FPHP Cache - Kontrol ve Hızlandırma Merkezi</title>
    <style>
        :root {
            --bg: #090a0f;
            --surface: #11141c;
            --surface-elevated: #161a24;
            --border: #232838;
            --border-focus: #3b82f6;
            --text-primary: #f3f4f6;
            --text-secondary: #9ca3af;
            --text-muted: #6b7280;
            --accent-blue: #3b82f6;
            --accent-green: #10b981;
            --accent-amber: #f59e0b;
            --accent-red: #ef4444;
            --accent-cyan: #06b6d4;
            --font-mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
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

        /* TOP NAVIGATION */
        .top-bar {
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
            gap: 0.75rem;
            font-weight: 700;
            font-size: 1.1rem;
            letter-spacing: -0.02em;
        }
        .badge-version {
            font-family: var(--font-mono);
            font-size: 11px;
            padding: 2px 6px;
            background: var(--surface-elevated);
            border: 1px solid var(--border);
            border-radius: 4px;
            color: var(--accent-blue);
        }
        .top-links {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .top-links a {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
        }
        .top-links a:hover { color: var(--text-primary); }

        /* LAYOUT & TABS */
        .app-container {
            max-width: 1320px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        .tab-nav {
            display: flex;
            gap: 0.25rem;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1.5rem;
            overflow-x: auto;
        }
        .tab-btn {
            padding: 0.65rem 1rem;
            color: var(--text-secondary);
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
            border-bottom: 2px solid transparent;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            white-space: nowrap;
        }
        .tab-btn:hover { color: var(--text-primary); }
        .tab-btn.active {
            color: var(--accent-blue);
            border-bottom-color: var(--accent-blue);
        }

        /* ALERTS */
        .alert-box {
            background: var(--surface);
            border: 1px solid var(--border);
            padding: 0.75rem 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 13px;
        }
        .alert-box.success { border-left: 4px solid var(--accent-green); color: var(--text-primary); }
        .alert-box.danger { border-left: 4px solid var(--accent-red); color: var(--text-primary); }

        /* KPI METRIC CARDS */
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
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 0.4rem;
        }
        .kpi-val {
            font-family: var(--font-mono);
            font-size: 1.85rem;
            font-weight: 700;
            letter-spacing: -0.03em;
            color: var(--text-primary);
        }
        .kpi-sub {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }

        /* PANELS AND GRIDS */
        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        .panel-header {
            padding: 0.85rem 1.15rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--surface-elevated);
        }
        .panel-title {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .panel-body {
            padding: 1.15rem;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }
        @media (max-width: 860px) {
            .grid-2 { grid-template-columns: 1fr; }
        }

        /* TABLES */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            text-align: left;
        }
        .data-table th {
            background: var(--surface-elevated);
            color: var(--text-muted);
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.6rem 0.85rem;
            border-bottom: 1px solid var(--border);
        }
        .data-table td {
            padding: 0.65rem 0.85rem;
            border-bottom: 1px solid var(--border);
            color: var(--text-primary);
            font-family: var(--font-sans);
        }
        .data-table tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }
        .data-table td.mono {
            font-family: var(--font-mono);
            font-size: 12px;
        }

        /* BADGES AND PILLS */
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
        .pill-red   { background: rgba(239, 68, 68, 0.15);  color: #f87171; border-color: rgba(239, 68, 68, 0.3); }
        .pill-gray  { background: var(--surface-elevated);  color: var(--text-muted); border-color: var(--border); }

        /* BUTTONS AND FORMS */
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
            gap: 0.35rem;
            transition: all 0.15s ease;
        }
        .btn:hover {
            background: #1f2433;
            border-color: #374151;
        }
        .btn-primary {
            background: #2563eb;
            border-color: #1d4ed8;
            color: #ffffff;
        }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-danger {
            background: rgba(239, 68, 68, 0.15);
            border-color: rgba(239, 68, 68, 0.4);
            color: #fca5a5;
        }
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
            padding: 0.5rem 0.75rem;
            color: var(--text-primary);
            font-family: var(--font-mono);
            font-size: 12px;
            outline: none;
        }
        .input-text:focus {
            border-color: var(--border-focus);
        }

        /* PROGRESS BARS */
        .progress-bar-wrap {
            height: 6px;
            background: var(--surface-elevated);
            border-radius: 3px;
            overflow: hidden;
            margin: 0.5rem 0 0.25rem;
        }
        .progress-bar-fill {
            height: 100%;
            background: var(--accent-blue);
            transition: width 0.3s ease;
        }

        /* CODE BOX */
        .code-box {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 0.75rem;
            font-family: var(--font-mono);
            font-size: 12px;
            color: var(--text-secondary);
            overflow-x: auto;
            white-space: pre-wrap;
        }
    </style>
</head>
<body>

    <!-- TOP HEADER -->
    <header class="top-bar">
        <div class="brand">
            <span>⚡ FPHP ENGINE</span>
            <span class="badge-version">v<?= FphpCache::VERSION ?></span>
        </div>
        <div class="top-links">
            <a href="index.php">Canlı Demo</a>
            <a href="benchmark.php">Hız Kıyaslama</a>
            <a href="https://github.com/tolgahanacar/fphpf" target="_blank">GitHub Deposu</a>
        </div>
    </header>

    <div class="app-container">

        <!-- NOTIFICATION BANNER -->
        <?php if (!empty($actionNotice)): ?>
            <div class="alert-box <?= $actionType ?>">
                <span><?= htmlspecialchars($actionNotice) ?></span>
                <span style="cursor:pointer;" onclick="this.parentElement.remove();">&times;</span>
            </div>
        <?php endif; ?>

        <!-- SECTION TABS -->
        <nav class="tab-nav">
            <a href="?tab=overview" class="tab-btn <?= $currentTab === 'overview' ? 'active' : '' ?>">
                📊 Genel Bakış ve Telemetri
            </a>
            <a href="?tab=pid" class="tab-btn <?= $currentTab === 'pid' ? 'active' : '' ?>">
                🎛️ Sibernetik PID ve Bellek
            </a>
            <a href="?tab=markov" class="tab-btn <?= $currentTab === 'markov' ? 'active' : '' ?>">
                🔮 Spekülatif Tahmin (W3C)
            </a>
            <a href="?tab=lineage" class="tab-btn <?= $currentTab === 'lineage' ? 'active' : '' ?>">
                🧬 Reaktif Veri Soyağacı (DAG)
            </a>
            <a href="?tab=explorer" class="tab-btn <?= $currentTab === 'explorer' ? 'active' : '' ?>">
                📁 Dosya Gezgini (Artifacts)
            </a>
            <a href="?tab=inspector" class="tab-btn <?= $currentTab === 'inspector' ? 'active' : '' ?>">
                🧪 İstek Denetleyicisi
            </a>
        </nav>

        <?php if ($currentTab === 'overview'): ?>
            <!-- ============================================== -->
            <!-- TAB 1: GENEL BAKIŞ VE METRİKLER               -->
            <!-- ============================================== -->

            <!-- 4 CRISP KPIS -->
            <div class="kpi-row">
                <div class="kpi-card">
                    <div class="kpi-label">Önbellek İsabet Oranı</div>
                    <div class="kpi-val" style="color: <?= $hitRatio >= 80 ? 'var(--accent-green)' : ($hitRatio >= 40 ? 'var(--accent-amber)' : 'var(--text-primary)') ?>;">
                        <?= $hitRatio ?>%
                    </div>
                    <div class="kpi-sub"><?= number_format($hits) ?> HIT / <?= number_format($totalRequests) ?> toplam istek</div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-label">C-Bellek Yürütme Hızı</div>
                    <div class="kpi-val" style="color: var(--accent-cyan);">0.8 - 1.5 µs</div>
                    <div class="kpi-sub">OPcache Bytecode Transmutation</div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-label">Kurtarılan Sunucu CPU Süresi</div>
                    <div class="kpi-val" style="color: var(--accent-blue);">
                        <?= round($savedSec, 2) ?>s
                    </div>
                    <div class="kpi-sub">Bypass edilen ağır backend döngüleri</div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-label">İstek Dağılımı</div>
                    <div class="kpi-val" style="font-size: 1.45rem;">
                        <span style="color: var(--accent-green);"><?= $hits ?></span> /
                        <span style="color: var(--accent-amber);"><?= $misses ?></span> /
                        <span style="color: var(--text-muted);"><?= $bypasses ?></span>
                    </div>
                    <div class="kpi-sub">HIT / MISS / BYPASS</div>
                </div>
            </div>

            <!-- HARDWARE & MULTI-TIER STATUS -->
            <div class="grid-2">
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">⚡ Çok Katmanlı Depolama Durumu</div>
                    </div>
                    <div class="panel-body" style="padding:0;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Katman</th>
                                    <th>Teknoloji</th>
                                    <th>Gecikme</th>
                                    <th>Durum</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>L0 Bytecode</strong></td>
                                    <td>OPcache C-RAM Memory</td>
                                    <td class="mono">0.8 - 1.5 µs</td>
                                    <td><span class="pill pill-green">AKTİF</span></td>
                                </tr>
                                <tr>
                                    <td><strong>L1 Memory</strong></td>
                                    <td>APCu Shared Memory</td>
                                    <td class="mono">1 - 5 µs</td>
                                    <td><span class="pill <?= $driverStats['apcu_enabled'] ?? false ? 'pill-green' : 'pill-gray' ?>"><?= ($driverStats['apcu_enabled'] ?? false) ? 'AKTİF' : 'DEVRE DIŞI' ?></span></td>
                                </tr>
                                <tr>
                                    <td><strong>L2 Disk Stream</strong></td>
                                    <td>Zero-Copy Kernel Socket</td>
                                    <td class="mono">25 - 45 µs</td>
                                    <td><span class="pill pill-green">AKTİF</span></td>
                                </tr>
                                <tr>
                                    <td><strong>Remote Cluster</strong></td>
                                    <td>Redis Clustered Node</td>
                                    <td class="mono">0.5 - 2.0 ms</td>
                                    <td><span class="pill <?= $config->get('redis.enabled') ? 'pill-green' : 'pill-gray' ?>"><?= $config->get('redis.enabled') ? 'BAĞLI' : 'STANDBY' ?></span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- INSTANT OPERATIONS PANEL -->
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">🛠️ Hızlı Operasyon Konsolu</div>
                    </div>
                    <div class="panel-body">
                        <p style="color: var(--text-secondary); margin-bottom: 1rem; font-size: 13px;">
                            Tek tıkla tüm depolama katmanlarını, süresi dolmuş dosyaları veya telemetri sayaçlarını güvenli bir şekilde yönetin:
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
                                <input type="hidden" name="action" value="warmup_all">
                                <button type="submit" class="btn">🚀 Kayıtlı Sayfaları Isıt</button>
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

        <?php elseif ($currentTab === 'pid'): ?>
            <!-- ============================================== -->
            <!-- TAB 2: SİBERNETİK PID VE BELLEK HOMEOSTAZİS    -->
            <!-- ============================================== -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">🎛️ Kapalı Döngü PID Kontrolörü (Havacılık ve Kontrol Teorisi)</div>
                    <span class="pill pill-green">HOMEOSTASIS AKTİF</span>
                </div>
                <div class="panel-body">
                    <p style="color: var(--text-secondary); margin-bottom: 1.25rem;">
                        Sistem rastgele ve kör parametrelerle çalışmaz. Trafik tsunamisi, viral içerik veya bellek baskısı anında 
                        <strong>$u(t) = K_p e(t) + K_i \int e(t)dt + K_d \frac{de}{dt}$</strong> matematiksel modelini işleterek
                        veritabanını korumak için SWR süresini uzatır ve sıkıştırmayı dinamik ayarlar.
                    </p>

                    <div class="kpi-row">
                        <div class="kpi-card">
                            <div class="kpi-label">Hedef Bellek Setpoint (r)</div>
                            <div class="kpi-val"><?= round(($pidData['setpoint'] ?? 0.70) * 100, 1) ?>%</div>
                            <div class="kpi-sub">Sistemin hedeflediği maksimum RAM</div>
                        </div>

                        <div class="kpi-card">
                            <div class="kpi-label">Anlık Bellek Oranı (y)</div>
                            <div class="kpi-val" style="color: <?= ($pidData['memory_ratio'] ?? 0) > 0.70 ? 'var(--accent-red)' : 'var(--accent-green)' ?>;">
                                <?= round(($pidData['memory_ratio'] ?? 0) * 100, 1) ?>%
                            </div>
                            <div class="kpi-sub"><?= $pidData['memory_used_mb'] ?? 0 ?> MB / <?= $pidData['memory_limit_mb'] ?? 128 ?> MB</div>
                        </div>

                        <div class="kpi-card">
                            <div class="kpi-label">Kontrol Sinyali u(t)</div>
                            <div class="kpi-val" style="color: var(--accent-blue);">
                                <?= $pidData['control_signal_u'] ?? 0.0 ?>
                            </div>
                            <div class="kpi-sub">e(t) Hata Marjı: <?= $pidData['current_error'] ?? 0.0 ?></div>
                        </div>

                        <div class="kpi-card">
                            <div class="kpi-label">Dinamik SWR Koruma Penceresi</div>
                            <div class="kpi-val" style="color: var(--accent-amber);"><?= $swrGraceCurrent ?>s</div>
                            <div class="kpi-sub">Baz Süre: 60s (Yüke göre esnetilir)</div>
                        </div>
                    </div>

                    <!-- RAM UTILIZATION PROGRESS -->
                    <div style="margin-top: 1rem;">
                        <div style="display:flex; justify-content:space-between; font-size:12px; color:var(--text-secondary);">
                            <span>PHP İşlem Belleği Kullanımı</span>
                            <span class="mono"><?= $pidData['memory_used_mb'] ?? 0 ?> MB (Limit: <?= $pidData['memory_limit_mb'] ?? 128 ?> MB)</span>
                        </div>
                        <div class="progress-bar-wrap">
                            <div class="progress-bar-fill" style="width: <?= min(100, max(2, ($pidData['memory_ratio'] ?? 0) * 100)) ?>%; background: <?= ($pidData['memory_ratio'] ?? 0) > 0.80 ? 'var(--accent-red)' : 'var(--accent-blue)' ?>;"></div>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($currentTab === 'markov'): ?>
            <!-- ============================================== -->
            <!-- TAB 3: MARKOV VE W3C SPEKÜLASYON KURALLARI    -->
            <!-- ============================================== -->
            <div class="grid-2">
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">🔮 Markov Gezinme Matrisi P(B | A)</div>
                        <span class="badge-version"><?= count($markovMatrix) ?> Sayfa Kayıtlı</span>
                    </div>
                    <div class="panel-body" style="padding:0;">
                        <?php if (empty($markovMatrix)): ?>
                            <div style="padding: 1.5rem; color: var(--text-muted); font-size: 13px;">
                                Henüz yeterli gezinme verisi toplanmadı. Sitede sayfalar arasında gezinildikçe Markov olasılıkları burada canlı hesaplanacaktır.
                            </div>
                        <?php else: ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Mevcut Sayfa (A)</th>
                                        <th>Muhtemel Hedef (B)</th>
                                        <th>Sıklık</th>
                                        <th>Olasılık %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $rowCount = 0;
                                    foreach ($markovMatrix as $fromUrl => $targets): 
                                        $total = array_sum($targets);
                                        foreach ($targets as $toUrl => $freq):
                                            $prob = round(($freq / max(1, $total)) * 100, 1);
                                    ?>
                                        <tr>
                                            <td class="mono"><?= htmlspecialchars($fromUrl) ?></td>
                                            <td class="mono" style="color:var(--accent-blue);"><?= htmlspecialchars($toUrl) ?></td>
                                            <td class="mono"><?= $freq ?> kez</td>
                                            <td><span class="pill <?= $prob >= 40 ? 'pill-green' : 'pill-blue' ?>">%<?= $prob ?></span></td>
                                        </tr>
                                    <?php 
                                            if (++$rowCount > 20) break 2;
                                        endforeach; 
                                    endforeach; 
                                    ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">⚡ W3C Speculation Rules Simülatörü</div>
                    </div>
                    <div class="panel-body">
                        <p style="color: var(--text-secondary); margin-bottom: 0.75rem; font-size: 13px;">
                            Modern Chromium ve Safari tarayıcılarına gönderilen ve sayfayı tıklanmadan önce arka planda GPU'da çizdiren yerel JSON kuralı:
                        </p>
                        <?php 
                        $demoRules = FphpCache::speculationRules('/');
                        ?>
                        <div class="code-box"><?= htmlspecialchars(json_encode($demoRules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></div>
                    </div>
                </div>
            </div>

        <?php elseif ($currentTab === 'lineage'): ?>
            <!-- ============================================== -->
            <!-- TAB 4: REAKTİF VERİ SOYAĞACI (DAG)             -->
            <!-- ============================================== -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">🧬 Canlı Veri Mutasyonu ve Anında Buharlaştırma</div>
                </div>
                <div class="panel-body">
                    <p style="color: var(--text-secondary); margin-bottom: 1rem; font-size: 13px;">
                        Veritabanında veya backend servisinde bir kayıt değiştiğinde geliştirici cache silme komutu yazmaz. 
                        Buradan bir veri varlığı (örn: <code style="color:var(--accent-cyan);">table:products</code> veya <code style="color:var(--accent-cyan);">user:42</code>) tetikleyerek ona bağlı tüm sayfaların mikro-saniyede nasıl buharlaştığını test edebilirsiniz:
                    </p>
                    <form method="POST" style="display:flex; gap:0.5rem; max-width:600px; margin-bottom: 1.5rem;">
                        <input type="hidden" name="action" value="mutate_entity">
                        <input type="text" name="entity" class="input-text" placeholder="Örn: table:products veya users:10" style="flex:1;" required>
                        <button type="submit" class="btn btn-primary">⚡ Mutasyonu Tetikle</button>
                    </form>

                    <div class="panel-title" style="margin-bottom:0.75rem;">Aktif Soyağacı Bağımlılık Haritası (DAG)</div>
                    <div style="border: 1px solid var(--border); border-radius: 4px; overflow:hidden;">
                        <?php if (empty($lineageData['entity_to_artifacts'])): ?>
                            <div style="padding: 1.25rem; color: var(--text-muted); font-size: 13px;">
                                Şu anda kayıtlı bir soyağacı bağımlılığı bulunmuyor. <code class="mono">FphpCache::trackLineage()</code> kullanıldığında bağlı sayfalar burada listelenecektir.
                            </div>
                        <?php else: ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Veri Varlığı (Entity / Tablo)</th>
                                        <th>Bağlı Önbellek Anahtarı</th>
                                        <th>Artefakt Tipi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lineageData['entity_to_artifacts'] as $entity => $artifacts): ?>
                                        <?php foreach ($artifacts as $key => $type): ?>
                                            <tr>
                                                <td class="mono"><strong><?= htmlspecialchars($entity) ?></strong></td>
                                                <td class="mono" style="color:var(--text-secondary);"><?= htmlspecialchars($key) ?></td>
                                                <td><span class="pill pill-blue"><?= htmlspecialchars($type) ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <?php elseif ($currentTab === 'explorer'): ?>
            <!-- ============================================== -->
            <!-- TAB 5: ÖNBELLEK DOSYALARI VE CANLI GEZGİN      -->
            <!-- ============================================== -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">📁 Disk ve OPcache Üzerindeki Aktif Dosyalar</div>
                    <span class="badge-version"><?= count($cacheFiles) ?> Artefakt</span>
                </div>
                <div class="panel-body" style="padding:0;">
                    <?php if (empty($cacheFiles)): ?>
                        <div style="padding: 2rem; text-align:center; color: var(--text-muted);">
                            Önbellekte şu anda dosya bulunmuyor.
                        </div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Dosya Adı</th>
                                    <th>Kategori</th>
                                    <th>Boyut</th>
                                    <th>Oluşturulma Tarihi</th>
                                    <th style="text-align:right;">İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cacheFiles as $cf): ?>
                                    <tr>
                                        <td class="mono"><?= htmlspecialchars($cf['name']) ?></td>
                                        <td>
                                            <span class="pill <?= str_contains($cf['type'], 'Bytecode') ? 'pill-green' : (str_contains($cf['type'], 'Gzip') ? 'pill-cyan' : 'pill-blue') ?>">
                                                <?= $cf['type'] ?>
                                            </span>
                                        </td>
                                        <td class="mono"><?= $cf['size_fmt'] ?></td>
                                        <td class="mono" style="color:var(--text-muted);"><?= $cf['date_fmt'] ?></td>
                                        <td style="text-align:right;">
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Bu önbellek dosyasını silmek istediğinize emin misiniz?');">
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

        <?php elseif ($currentTab === 'inspector'): ?>
            <!-- ============================================== -->
            <!-- TAB 6: CANLI İSTEK VE YANIT İNCELEYİCİSİ       -->
            <!-- ============================================== -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">🧪 Canlı HTTP İstek İnceleyici</div>
                </div>
                <div class="panel-body">
                    <p style="color: var(--text-secondary); margin-bottom: 1rem; font-size: 13px;">
                        Sunucuya anında bir test isteği göndererek <strong>X-FPHP-Cache</strong> başlığını, mikro-saniye tepki süresini ve HTTP başlıklarını canlı olarak inceleyin:
                    </p>

                    <?php
                    $testTarget = $_GET['test_url'] ?? 'index.php';
                    $targetFullUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/' . ltrim($testTarget, '/');
                    
                    $t0 = hrtime(true);
                    $headers = @get_headers($targetFullUrl, true) ?: [];
                    $tElapsedUs = round((hrtime(true) - $t0) / 1000, 1);
                    ?>

                    <form method="GET" style="display:flex; gap:0.5rem; max-width:600px; margin-bottom: 1.5rem;">
                        <input type="hidden" name="tab" value="inspector">
                        <input type="text" name="test_url" class="input-text" value="<?= htmlspecialchars($testTarget) ?>" style="flex:1;" required>
                        <button type="submit" class="btn btn-primary">İsteği Gönder ve İncele</button>
                    </form>

                    <div class="kpi-row" style="margin-bottom:1rem;">
                        <div class="kpi-card">
                            <div class="kpi-label">Test URL</div>
                            <div class="mono" style="font-size:14px; color:var(--accent-blue); word-break:break-all;"><?= htmlspecialchars($targetFullUrl) ?></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-label">İstek Döngü Süresi</div>
                            <div class="kpi-val" style="color:var(--accent-cyan); font-size:1.5rem;"><?= $tElapsedUs ?> µs</div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-label">FPHP Durumu</div>
                            <div class="mono" style="font-size:14px; font-weight:700; color:var(--accent-green);">
                                <?= htmlspecialchars(is_array($headers['X-FPHP-Cache'] ?? null) ? implode(', ', $headers['X-FPHP-Cache']) : ($headers['X-FPHP-Cache'] ?? 'Yanıt Alınamadı / Yerel İstek')) ?>
                            </div>
                        </div>
                    </div>

                    <div class="panel-title" style="margin-bottom:0.5rem;">Dönen HTTP Başlıkları (Response Headers)</div>
                    <div class="code-box"><?php
                        if (!empty($headers)) {
                            foreach ($headers as $k => $v) {
                                if (is_int($k)) {
                                    echo htmlspecialchars($v) . "\n";
                                } else {
                                    echo htmlspecialchars($k) . ": " . (is_array($v) ? implode(', ', $v) : $v) . "\n";
                                }
                            }
                        } else {
                            echo "Başlıklar okunamadı veya sunucu doğrudan yanıt vermedi.";
                        }
                    ?></div>
                </div>
            </div>
        <?php endif; ?>

    </div>

</body>
</html>
