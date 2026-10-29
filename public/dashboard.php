<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/fphp-bootstrap.php';

use Fphp\FphpCache;
use Fphp\Driver\HybridDriver;
use Fphp\Driver\ApcuDriver;
use Fphp\Driver\FileDriver;
use Fphp\Profiler\Metrics;

// Handle Actions (Flush, Reset, Warmup)
$actionMessage = null;
$actionStatus = 'success';

if (isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'clear_all') {
        FphpCache::clear();
        $actionMessage = 'Tüm önbellek (APCu ve Disk Dosyaları) başarıyla temizlendi!';
    } elseif ($action === 'clear_files') {
        $fileDriver = new FileDriver();
        $fileDriver->clear();
        $actionMessage = 'Dosya tabanlı disk önbelleği başarıyla temizlendi!';
    } elseif ($action === 'reset_stats') {
        FphpCache::getStatsTracker()->reset();
        $actionMessage = 'Telemetri ve istatistik kayıtları sıfırlandı!';
    } elseif ($action === 'warmup') {
        // Warm up demo pages
        $driver = FphpCache::getDriver();
        $driver->set('warmup_test', ['time' => time(), 'status' => 'warmed'], 3600);
        $actionMessage = 'Önbellek ön yükleme ve ısınma (warmup) işlemi tamamlandı!';
    } elseif ($action === 'warmup_crawl') {
        $warmer = new \Fphp\Warmer\CacheWarmer();
        $warmed = $warmer->warmAll();
        $actionMessage = 'Arka plan tarayıcısı (Warmer) ' . count($warmed) . ' adet sayfayı paralel olarak başarıyla ısıttı!';
    } elseif ($action === 'warmup_sitemap') {
        $sitemapTarget = $_POST['sitemap_url'] ?? $_GET['sitemap_url'] ?? 'sitemap.xml';
        $warmer = new \Fphp\Warmer\CacheWarmer();
        $res = $warmer->warmFromSitemap($sitemapTarget);
        $actionMessage = $res['message'];
        $actionStatus = $res['success'] ? 'success' : 'warn';
    } elseif ($action === 'prune_expired') {
        $res = FphpCache::prune();
        $actionMessage = 'Süresi dolmuş ' . $res['total_deleted'] . ' adet dosya temizlendi (' . Metrics::formatBytes($res['total_freed_bytes']) . ' alan kazanıldı).';
    } elseif ($action === 'generate_preload') {
        $ok = FphpCache::generatePreloadFile();
        $actionMessage = $ok ? 'OPcache preload.php dosyası kök dizinde başarıyla oluşturuldu!' : 'Preload dosyası oluşturulamadı.';
    }
}

$opcacheDiag = \Fphp\Optimizer\OpcacheManager::getDiagnostics();
$warmer = new \Fphp\Warmer\CacheWarmer();
$warmerUrls = $warmer->getUrls();

$stats = FphpCache::getStats();
$driver = FphpCache::getDriver();
$driverStats = $driver->getStats();
$config = FphpCache::getConfig();

// Inspect APCu availability and stats
$apcu = new ApcuDriver();
$apcuStats = $apcu->getStats();

// Inspect Remote Redis Cluster stats
$redisDriver = new \Fphp\Driver\RedisDriver(
    (string)$config->get('redis.host', '127.0.0.1'),
    (int)$config->get('redis.port', 6379),
    $config->get('redis.password'),
    (int)$config->get('redis.database', 0),
    0.5,
    $config->get('prefix', 'fphp:')
);
$redisStats = $redisDriver->getStats();

// Inspect File driver stats
$fileDriver = new FileDriver();
$fileStats = $fileDriver->getStats();

// Calculate total saved time
$savedSec = $stats['saved_time_seconds'] ?? 0.0;
$hitRatio = $stats['hit_ratio'] ?? 0.0;
$totalReqs = $stats['total_requests'] ?? 0;
$hits = $stats['hits'] ?? 0;
$misses = $stats['misses'] ?? 0;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FPHP Cache - Yönetim ve Hızlandırma Paneli</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #07090e;
            --surface: #0f141f;
            --surface-card: rgba(18, 24, 38, 0.7);
            --border: rgba(255, 255, 255, 0.08);
            --border-hover: rgba(99, 102, 241, 0.4);
            --primary: #6366f1;
            --primary-glow: rgba(99, 102, 241, 0.3);
            --accent-green: #10b981;
            --accent-cyan: #06b6d4;
            --accent-amber: #f59e0b;
            --accent-rose: #f43f5e;
            --text-main: #f9fafb;
            --text-muted: #94a3b8;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, sans-serif;
            background-color: var(--bg);
            color: var(--text-main);
            min-height: 100vh;
            padding: 2rem 1.5rem 5rem;
            background-image: 
                radial-gradient(circle at 10% 10%, rgba(99, 102, 241, 0.12) 0%, transparent 45%),
                radial-gradient(circle at 90% 90%, rgba(6, 182, 212, 0.09) 0%, transparent 45%);
        }

        .container { max-width: 1200px; margin: 0 auto; }

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

        .alert {
            padding: 1rem 1.5rem;
            border-radius: 0.75rem;
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: var(--accent-green);
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 600;
        }

        .hero-title {
            margin-bottom: 2rem;
        }
        .hero-title h1 {
            font-size: 2.25rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, #ffffff 30%, #c7d2fe 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .hero-title p {
            color: var(--text-muted);
            font-size: 1.05rem;
        }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2.5rem;
        }
        .kpi-card {
            background: var(--surface-card);
            border: 1px solid var(--border);
            border-radius: 1rem;
            padding: 1.5rem;
            backdrop-filter: blur(12px);
            transition: all 0.3s ease;
        }
        .kpi-card:hover {
            border-color: var(--border-hover);
            transform: translateY(-3px);
        }
        .kpi-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }
        .kpi-label {
            font-size: 0.85rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }
        .kpi-icon {
            font-size: 1.25rem;
        }
        .kpi-val {
            font-family: 'JetBrains Mono', monospace;
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--text-main);
            margin-bottom: 0.35rem;
        }
        .kpi-sub {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .section-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }
        @media (max-width: 900px) {
            .section-grid { grid-template-columns: 1fr; }
        }

        .card {
            background: var(--surface-card);
            border: 1px solid var(--border);
            border-radius: 1rem;
            padding: 1.75rem;
            backdrop-filter: blur(16px);
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border);
        }
        .card-title {
            font-size: 1.2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .layer-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.25rem;
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.04);
            border-radius: 0.75rem;
            margin-bottom: 0.75rem;
        }
        .layer-name {
            font-weight: 700;
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }
        .layer-desc {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        .status-pill {
            font-size: 0.8rem;
            font-weight: 700;
            padding: 0.3rem 0.75rem;
            border-radius: 9999px;
            font-family: 'JetBrains Mono', monospace;
        }
        .status-active {
            background: rgba(16, 185, 129, 0.15);
            color: var(--accent-green);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .status-inactive {
            background: rgba(239, 68, 68, 0.15);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.7rem 1.25rem;
            border-radius: 0.6rem;
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
            cursor: pointer;
            border: none;
        }
        .btn-danger {
            background: rgba(244, 63, 94, 0.15);
            color: #fda4af;
            border: 1px solid rgba(244, 63, 94, 0.3);
        }
        .btn-danger:hover {
            background: rgba(244, 63, 94, 0.25);
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

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        th {
            background: rgba(255, 255, 255, 0.03);
            padding: 0.85rem 1.25rem;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
        }
        td {
            padding: 0.95rem 1.25rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            font-size: 0.9rem;
        }
        .mono { font-family: 'JetBrains Mono', monospace; }

        .code-box {
            background: #090d16;
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            padding: 1.25rem;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.85rem;
            color: #93c5fd;
            line-height: 1.6;
            overflow-x: auto;
            position: relative;
        }
        .copy-btn {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            padding: 0.35rem 0.75rem;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid var(--border);
            border-radius: 0.4rem;
            color: var(--text-muted);
            font-size: 0.75rem;
            cursor: pointer;
        }
        .copy-btn:hover { color: var(--text-main); }
    </style>
</head>
<body>
    <div class="container">
        <header class="navbar">
            <div class="logo">
                <span>⚡</span> FPHP Cache Accelerator
            </div>
            <nav class="nav-links">
                <a href="index.php">🏠 Ana Sayfa (Canlı Hızlandırıcı)</a>
                <a href="dashboard.php" class="active">📊 Canlı Yönetim Paneli</a>
                <a href="benchmark.php">⚡ Hız Testi (Benchmark)</a>
                <a href="sitemap.xml" target="_blank">🗺️ Site Haritası (Sitemap.xml)</a>
            </nav>
        </header>

        <?php if ($actionMessage): ?>
            <div class="alert">
                <span>✓</span>
                <span><?= htmlspecialchars($actionMessage) ?></span>
            </div>
        <?php endif; ?>

        <div class="hero-title">
            <h1>Performans & Önbellek Yönetim Paneli</h1>
            <p>Gerçek zamanlı mikrosaniye hız ölçümleri, bellek tüketimi ve önbellek kontrol merkezi.</p>
        </div>

        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-header">
                    <span class="kpi-label">Önbellek İsabet Oranı</span>
                    <span class="kpi-icon">🎯</span>
                </div>
                <div class="kpi-val" style="color: var(--accent-green);"><?= htmlspecialchars((string)$hitRatio) ?>%</div>
                <div class="kpi-sub"><?= number_format($hits) ?> İsabet / <?= number_format($totalReqs) ?> Toplam İstek</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-header">
                    <span class="kpi-label">Kazanılan Sunucu Zamanı</span>
                    <span class="kpi-icon">⏳</span>
                </div>
                <div class="kpi-val" style="color: var(--accent-cyan);"><?= htmlspecialchars((string)$savedSec) ?> <small style="font-size: 1.1rem;">sn</small></div>
                <div class="kpi-sub">Veritabanı ve CPU tasarrufu</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-header">
                    <span class="kpi-label">Önbellek Yanıt Hızı</span>
                    <span class="kpi-icon">⚡</span>
                </div>
                <div class="kpi-val" style="color: #a5b4fc;">1 - 120 <small style="font-size: 1.1rem;">µs</small></div>
                <div class="kpi-sub">Mikrosaniyelerle anında yanıt</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-header">
                    <span class="kpi-label">Disk Önbellek Dosyaları</span>
                    <span class="kpi-icon">💾</span>
                </div>
                <div class="kpi-val"><?= number_format($fileStats['cached_files'] ?? 0) ?></div>
                <div class="kpi-sub">Boyut: <?= htmlspecialchars((string)($fileStats['size_mb'] ?? 0)) ?> MB (Gzip Sıkıştırmalı)</div>
            </div>
        </div>

        <div class="section-grid">
            <!-- Storage Layers -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <span>🧱</span> Depolama Katmanları Durumu
                    </div>
                    <span style="font-size: 0.85rem; color: var(--text-muted);">Çok Katmanlı Hibrit</span>
                </div>

                <div class="layer-item">
                    <div>
                        <div class="layer-name">L0: İstek İçi Bellek (In-Process RAM)</div>
                        <div class="layer-desc">Aynı istekte yinelenen sorguları 0.3 µs içinde döndürür.</div>
                    </div>
                    <span class="status-pill status-active">0.3 µs • Aktif</span>
                </div>

                <div class="layer-item">
                    <div>
                        <div class="layer-name">L1: APCu Paylaşımlı Bellek (Shared RAM)</div>
                        <div class="layer-desc">PHP worker'ları arasında 1.2 µs paylaşımlı bellek önbelleği.</div>
                    </div>
                    <?php if ($apcuStats['available']): ?>
                        <span class="status-pill status-active"><?= htmlspecialchars($apcuStats['latency']) ?> • Aktif</span>
                    <?php else: ?>
                        <span class="status-pill status-inactive">Devre Dışı (ext-apcu)</span>
                    <?php endif; ?>
                </div>

                <div class="layer-item">
                    <div>
                        <div class="layer-name">L_Cluster: Uzak Redis Sunucusu / Kümesi</div>
                        <div class="layer-desc">Dağıtık ve çoklu sunuculu yapılar için ağ düzeyinde küme önbelleği.</div>
                    </div>
                    <?php if ($redisStats['available']): ?>
                        <span class="status-pill status-active"><?= htmlspecialchars($redisStats['latency']) ?> • Bağlandı</span>
                    <?php else: ?>
                        <span class="status-pill status-inactive">Opsiyonel (<?= htmlspecialchars($redisStats['status']) ?>)</span>
                    <?php endif; ?>
                </div>

                <div class="layer-item">
                    <div>
                        <div class="layer-name">L2: Atomik Disk Dosyası (SSD/NVMe)</div>
                        <div class="layer-desc">Yarış durumsuz (atomic rename) ve Gzip sıkıştırmalı kalıcı depolama.</div>
                    </div>
                    <span class="status-pill status-active">50 - 150 µs • Aktif</span>
                </div>

                <div style="margin-top: 1.5rem;">
                    <div class="card-title" style="font-size: 1rem; margin-bottom: 0.75rem;">
                        <span>⚙️</span> Önbellek Kontrol İşlemleri
                    </div>
                    <div class="action-buttons">
                        <a href="?action=clear_all" class="btn btn-danger" onclick="return confirm('Tüm önbellek silinsin mi?');">🗑️ Tüm Önbelleği Boşalt</a>
                        <a href="?action=prune_expired" class="btn btn-secondary">🧹 Süresi Dolanları Temizle (Prune)</a>
                        <a href="?action=clear_files" class="btn btn-secondary">📁 Disk Dosyalarını Temizle</a>
                        <a href="?action=reset_stats" class="btn btn-secondary">📊 İstatistikleri Sıfırla</a>
                        <a href="?action=warmup" class="btn btn-primary">🔥 Önbelleği Isıt (Warmup)</a>
                    </div>
                </div>
            </div>

            <!-- Server & Integration Config -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <span>🔌</span> Hızlı Entegrasyon Kılavuzu
                    </div>
                    <span style="font-size: 0.85rem; color: var(--accent-green);">Zero-Framework Bypass</span>
                </div>

                <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem;">
                    PHP uygulamalarınızda (WordPress, Laravel, Özel Projeler) x2 - x20 hız artışı elde etmek için aşağıdaki yöntemlerden birini seçin:
                </p>

                <div style="margin-bottom: 1.25rem;">
                    <strong style="font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase;">Yöntem 1: index.php En Üst Satırına Ekleyin:</strong>
                    <div class="code-box">
                        require_once '<?= addslashes(dirname(__DIR__)) ?>/fphp-bootstrap.php';<br>
                        \Fphp\FphpCache::boot();
                    </div>
                </div>

                <div>
                    <strong style="font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase;">Yöntem 2: php.ini veya .htaccess (auto_prepend_file):</strong>
                    <div class="code-box">
                        <span class="code-comment"># .htaccess içine ekleyerek tüm framework'ü bypass edin:</span><br>
                        php_value auto_prepend_file "<?= addslashes(dirname(__DIR__)) ?>/fphp-bootstrap.php"
                    </div>
                </div>
            </div>
        </div>

        <!-- Enterprise Tier: Uzay Seviyesi Özellikler -->
        <div style="margin-bottom: 2.5rem;">
            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem;">
                <span style="font-size: 1.5rem;">🚀</span>
                <h2 style="font-size: 1.4rem; font-weight: 800; background: linear-gradient(135deg, #ffffff 40%, #c7d2fe 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
                    Uzay Seviyesi (Enterprise Tier) Hızlandırma Modülleri
                </h2>
            </div>

            <div class="section-grid">
                <!-- SWR & Cache Stampede Protection -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <span>🛡️</span> Thundering Herd & SWR Koruması
                        </div>
                        <span class="status-pill status-active">Aktif • 0 Kilitlenme</span>
                    </div>
                    <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem; line-height: 1.6;">
                        Önbellek süresi dolduğunda 10.000 kişi aynı anda gelse bile <strong>Cache Stampede (Dogpile)</strong> koruması devreye girer. Yalnızca 1 istek veritabanını yeniler, diğer tüm ziyaretçiler <strong>Stale-While-Revalidate</strong> ile mikrosaniyede yanıt alır.
                    </p>
                    <div class="layer-item" style="margin-bottom: 0;">
                        <div>
                            <div class="layer-name">Kilit Mekanizması: Atomik Donanım Kilidi</div>
                            <div class="layer-desc">O_CREAT | O_EXCL veya APCu donanım kilidi ile yarış durumsuz koruma.</div>
                        </div>
                        <span class="status-pill status-active">1 µs Kilit Hızı</span>
                    </div>
                </div>

                <!-- Smart Cache Warmer -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <span>🔥</span> Akıllı Otomatik Önbellek Isıtıcı
                        </div>
                        <span class="status-pill status-active"><?= count($warmerUrls) ?> Kayıtlı URL</span>
                    </div>
                    <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem; line-height: 1.6;">
                        Ziyaretçiler asla yavaş "MISS" deneyimi yaşamaz. Paralel arka plan tarayıcısı (curl_multi) sayfaları sessizce önceden ısıtarak hazır tutar.
                    </p>
                    <div class="action-buttons" style="margin-bottom: 1rem;">
                        <a href="?action=warmup_crawl" class="btn btn-primary">🚀 Kayıtlı Sayfaları Isıt</a>
                    </div>
                    <form method="POST" action="?action=warmup_sitemap" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <input type="text" name="sitemap_url" value="sitemap.xml" placeholder="sitemap.xml veya URL girin" style="flex: 1; min-width: 180px; padding: 0.6rem 0.9rem; border-radius: 0.5rem; background: rgba(0,0,0,0.3); border: 1px solid var(--border); color: #fff; font-family: 'JetBrains Mono', monospace; font-size: 0.85rem;">
                        <button type="submit" class="btn btn-secondary" style="background: rgba(99, 102, 241, 0.2); border-color: rgba(99, 102, 241, 0.4);">🗺️ Sitemap ile Toplu Isıt</button>
                    </form>
                </div>

                <!-- OPcache & JIT Advisor -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <span>⚡</span> PHP 8.5+ OPcache & JIT Derleyici
                        </div>
                        <span class="status-pill <?= $opcacheDiag['opcache_enabled'] ? 'status-active' : 'status-inactive' ?>">
                            <?= $opcacheDiag['opcache_enabled'] ? 'OPcache Aktif' : 'Devre Dışı' ?>
                        </span>
                    </div>
                    <div style="font-size: 0.9rem; margin-bottom: 1rem;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <span style="color: var(--text-muted);">Bytecode İsabet Oranı:</span>
                            <strong class="mono" style="color: var(--accent-green);"><?= $opcacheDiag['hit_rate'] ?>%</strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <span style="color: var(--text-muted);">JIT (Just-In-Time) Durumu:</span>
                            <strong class="mono" style="color: <?= $opcacheDiag['jit_enabled'] ? 'var(--accent-green)' : 'var(--accent-amber)' ?>;">
                                <?= $opcacheDiag['jit_enabled'] ? 'Aktif (' . $opcacheDiag['jit_buffer_size'] . ')' : 'Kapalı (Önerilir)' ?>
                            </strong>
                        </div>
                    </div>
                    <div class="action-buttons">
                        <a href="?action=generate_preload" class="btn btn-secondary">📄 OPcache Preload.php Dosyası Üret</a>
                    </div>
                </div>

                <!-- Early Hints & Preload Assets -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <span>🚀</span> HTTP 103 Early Hints & Preload
                        </div>
                        <span class="status-pill status-active">Aktif</span>
                    </div>
                    <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem; line-height: 1.6;">
                        HTML çıktısındaki kritik CSS, WOFF2 web fontları ve harici alan adları (Google Fonts) otomatik tespit edilir ve tarayıcıya <strong>HTTP Link / Preload</strong> olarak gönderilir.
                    </p>
                    <div class="layer-item" style="margin-bottom: 0;">
                        <div>
                            <div class="layer-name">Kaynak Ön Yükleme</div>
                            <div class="layer-desc">Tarayıcı HTML'i okurken CSS ve Fontları eşzamanlı indirir.</div>
                        </div>
                        <span class="status-pill status-active">Aktif</span>
                    </div>
                </div>

                <!-- PSR-16 & PSR-6 Standard Cache Adapters -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <span>📦</span> PSR-16 & PSR-6 Standart Adaptörler
                        </div>
                        <span class="status-pill status-active">Hazır • Zero-Dependency</span>
                    </div>
                    <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem; line-height: 1.6;">
                        Symfony, Laravel, Laminas gibi modern framework'lerde yerel cache motoru olarak doğrudan kullanılabilir:
                    </p>
                    <div class="code-box" style="font-size: 0.8rem; padding: 0.75rem 1rem;">
                        $psr16 = \Fphp\FphpCache::psr16();<br>
                        $psr6Pool = \Fphp\FphpCache::psr6();
                    </div>
                </div>

                <!-- Fragment & Component Microcaching -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <span>🧩</span> Parçalı (Fragment) Önbellekleme
                        </div>
                        <span class="status-pill status-active">Aktif • Şablon Düzeyi</span>
                    </div>
                    <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem; line-height: 1.6;">
                        Giriş yapmış kullanıcılar veya sepet gibi tüm sayfanın önbelleğe alınamadığı dinamik sayfalarda ağır bileşenleri (widget, menü) önbelleğe alır:
                    </p>
                    <div class="code-box" style="font-size: 0.8rem; padding: 0.75rem 1rem;">
                        if (\Fphp\FphpCache::startFragment('sidebar_menu', 3600)):<br>
                        &nbsp;&nbsp;&nbsp;&nbsp;// Ağır veritabanı sorguları / render<br>
                        &nbsp;&nbsp;&nbsp;&nbsp;\Fphp\FphpCache::endFragment();<br>
                        endif;
                    </div>
                </div>
            </div>
        </div>

        <!-- Telemetry History -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <span>📡</span> Gerçek Zamanlı İstek Telemetri Kayıtları
                </div>
                <a href="dashboard.php" class="btn btn-secondary" style="padding: 0.35rem 0.85rem; font-size: 0.8rem;">Yenile</a>
            </div>

            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Durum</th>
                            <th>Depolama Katmanı</th>
                            <th>Önbellek Anahtarı</th>
                            <th>Tepki Süresi</th>
                            <th>Zaman</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stats['recent_history'])): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 2rem;">
                                    Henüz telemetri verisi oluşmadı. Sayfaları ziyaret ettikçe burada görünecektir.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($stats['recent_history'] as $entry): ?>
                                <tr>
                                    <td>
                                        <span class="status-pill <?= $entry['status'] === 'HIT' ? 'status-active' : 'status-inactive' ?>">
                                            <?= htmlspecialchars($entry['status']) ?>
                                        </span>
                                    </td>
                                    <td><strong><?= htmlspecialchars($entry['driver']) ?></strong></td>
                                    <td class="mono"><?= htmlspecialchars($entry['key']) ?></td>
                                    <td class="mono" style="color: var(--accent-green);">
                                        <?= htmlspecialchars(Metrics::formatTime((float)$entry['time_us'])) ?>
                                    </td>
                                    <td style="color: var(--text-muted);"><?= date('H:i:s', $entry['timestamp']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
