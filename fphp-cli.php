<?php

declare(strict_types=1);

/**
 * FPHP Cache - Enterprise CLI Management Tool
 * 
 * Usage:
 *   php fphp-cli.php stats
 *   php fphp-cli.php clear
 *   php fphp-cli.php prune [--age=3600]
 *   php fphp-cli.php warmup [--sitemap=public/sitemap.xml]
 *   php fphp-cli.php preload
 *   php fphp-cli.php bench [--iter=5000]
 */

if (PHP_SAPI !== 'cli') {
    die("This tool can only be run from the command line (CLI).\n");
}

require_once __DIR__ . '/fphp-bootstrap.php';

use Fphp\FphpCache;
use Fphp\Profiler\Metrics;
use Fphp\Warmer\CacheWarmer;
use Fphp\Optimizer\OpcacheManager;

$args = $argv;
array_shift($args); // remove script name
$command = strtolower($args[0] ?? 'help');

// Parse flags (e.g. --sitemap=path, --iter=5000)
$flags = [];
foreach ($args as $arg) {
    if (str_starts_with($arg, '--')) {
        $parts = explode('=', substr($arg, 2), 2);
        $flags[$parts[0]] = $parts[1] ?? true;
    }
}

echo "\n⚡ FPHP Cache CLI Tool (v" . FphpCache::VERSION . ")\n";
echo str_repeat('-', 55) . "\n";

switch ($command) {
    case 'stats':
        $stats = FphpCache::getStats();
        $driver = FphpCache::getDriver();
        $driverStats = $driver->getStats();
        $opcache = OpcacheManager::getDiagnostics();

        echo "📊 ÖNBELLEK VE SİSTEM İSTATİSTİKLERİ:\n";
        echo "  • Aktif Sürücü       : " . $driverStats['name'] . " (" . ($driverStats['type'] ?? 'N/A') . ")\n";
        echo "  • Tepki Gecikmesi    : " . ($driverStats['latency'] ?? '1 - 10 µs') . "\n";
        echo "  • Toplam İstek       : " . number_format($stats['total_requests']) . "\n";
        echo "  • İsabet (HIT)       : " . number_format($stats['hits']) . "\n";
        echo "  • Iska (MISS)        : " . number_format($stats['misses']) . "\n";
        echo "  • Atlama (BYPASS)    : " . number_format($stats['bypasses']) . "\n";
        echo "  • İsabet Oranı       : " . $stats['hit_ratio'] . "%\n";
        echo "  • Kazanılan Zaman    : " . $stats['saved_time_seconds'] . " saniye CPU süresi\n";
        echo "\n⚙️ SUNUCU VE DERLEYİCİ DURUMU:\n";
        echo "  • Zend OPcache       : " . ($opcache['opcache_enabled'] ? 'AKTİF (Hit Rate: ' . $opcache['hit_rate'] . '%)' : 'DEVRE DIŞI') . "\n";
        echo "  • JIT Derleyicisi    : " . ($opcache['jit_enabled'] ? 'AKTİF (' . $opcache['jit_buffer_size'] . ')' : 'DEVRE DIŞI') . "\n";
        echo "  • PHP Versiyonu      : PHP " . PHP_VERSION . "\n";
        break;

    case 'clear':
    case 'flush':
        echo "🗑️ Tüm önbellek temizleniyor...\n";
        $ok = FphpCache::clear();
        echo $ok ? " [BAŞARILI] APCu, Redis ve Disk önbelleği tamamen boşaltıldı.\n" : " [HATA] Temizleme işlemi başarısız.\n";
        break;

    case 'prune':
    case 'gc':
        $maxAge = isset($flags['age']) ? (int)$flags['age'] : 0;
        echo "🧹 Süresi dolmuş önbellek dosyaları taranıyor...\n";
        $pruned = FphpCache::prune($maxAge);
        echo " [BAŞARILI] Toplam Silinen : " . number_format($pruned['total_deleted']) . " adet dosya\n";
        echo "            Kazanılan Alan : " . Metrics::formatBytes($pruned['total_freed_bytes']) . "\n";
        break;

    case 'warmup':
        $warmer = new CacheWarmer();
        if (isset($flags['sitemap'])) {
            $sitemap = (string)$flags['sitemap'];
            echo "🗺️ Sitemap ayrıştırılıyor: {$sitemap}...\n";
            $res = $warmer->warmFromSitemap($sitemap);
            echo " " . ($res['success'] ? '[BAŞARILI]' : '[HATA]') . " " . $res['message'] . "\n";
        } else {
            echo "🚀 Kayıtlı sayfalar paralel taranıyor...\n";
            $results = $warmer->warmAll();
            echo " [BAŞARILI] " . count($results) . " adet sayfa paralel olarak ısıtıldı:\n";
            foreach ($results as $item) {
                echo "   - [HTTP " . $item['http_code'] . "] " . $item['latency_ms'] . " ms: " . $item['url'] . "\n";
            }
        }
        break;

    case 'preload':
        echo "📄 OPcache preload.php derleme betiği oluşturuluyor...\n";
        $ok = FphpCache::generatePreloadFile();
        echo $ok ? " [BAŞARILI] preload.php başarıyla oluşturuldu.\n" : " [HATA] Preload dosyası oluşturulamadı.\n";
        break;

    case 'bench':
    case 'benchmark':
        $iter = isset($flags['iter']) ? (int)$flags['iter'] : 5000;
        echo "⚡ Mikrosaniye performans benchmark'ı başlatılıyor (İşlem: " . number_format($iter) . ")...\n\n";
        passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/public/benchmark.php'));
        break;

    case 'delete-prefix':
    case 'purge-prefix':
        $prefix = $args[1] ?? '';
        if (empty($prefix)) {
            echo " [HATA] Lütfen silinecek ön eki belirtin. Örnek: php fphp-cli.php delete-prefix user:\n";
            break;
        }
        echo "🗑️ '{$prefix}' ön ekiyle eşleşen anahtarlar siliniyor...\n";
        $count = FphpCache::deletePrefix($prefix);
        echo " [BAŞARILI] Toplam {$count} adet önbellek kaydı silindi.\n";
        break;

    case 'sidecar':
        $server = isset($flags['server']) ? (string)$flags['server'] : 'nginx';
        echo "⚡ Web sunucusu Zero-PHP sidecar konfigürasyonu üretiliyor ({$server})...\n\n";
        echo FphpCache::sidecar($server) . "\n";
        break;

    case 'predict':
        $url = $args[1] ?? '/';
        $markov = FphpCache::getMarkovWarmer();
        if ($markov === null) {
            echo " [BİLGİ] Tahminsel Markov ısıtıcı bu konfigürasyonda kapalı.\n";
            break;
        }
        $predictions = $markov->predictNext($url, 0.10);
        echo "🔮 '{$url}' sayfasından sonraki olası gezinme tahminleri:\n";
        if (empty($predictions)) {
            echo "   (Henüz yeterli gezinme verisi toplanmadı)\n";
        } else {
            foreach ($predictions as $target => $prob) {
                echo "   • {$target} -> %" . round($prob * 100, 1) . " olasılık\n";
            }
        }
        break;

    case 'help':
    default:
        echo "Kullanılabilir Komutlar:\n";
        echo "  php fphp-cli.php stats           Önbellek, isabet oranları ve bellek durumunu gösterir\n";
        echo "  php fphp-cli.php clear           Tüm önbellek katmanlarını temizler (purge)\n";
        echo "  php fphp-cli.php delete-prefix <prefix>  Belirli bir ön ekle başlayan tüm kayıtları siler\n";
        echo "  php fphp-cli.php prune           Süresi dolmuş dosyaları diskten temizler (GC)\n";
        echo "  php fphp-cli.php warmup          Kayıtlı sayfaları arka planda ısıtır\n";
        echo "  php fphp-cli.php warmup --sitemap=public/sitemap.xml  Sitemap URL'lerini paralel ısıtır\n";
        echo "  php fphp-cli.php sidecar --server=nginx  Web sunucusu doğrudan çekirdek bypass kuralı üretir\n";
        echo "  php fphp-cli.php predict <url>   Tahminsel gezinme olasılıklarını listeler\n";
        echo "  php fphp-cli.php preload         OPcache preload.php dosyasını günceller\n";
        echo "  php fphp-cli.php bench           Canlı performans hız testini çalıştırır\n";
        break;
}

echo str_repeat('-', 55) . "\n\n";
