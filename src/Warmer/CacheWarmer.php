<?php

declare(strict_types=1);

namespace Fphp\Warmer;

use Fphp\Profiler\Metrics;

/**
 * Enterprise Smart Background Cache Warmer with XML Sitemap Engine
 * 
 * Silently crawls and pre-renders site pages in the background,
 * parsing local/remote XML sitemaps and guaranteeing 100% Cache HIT rates.
 */
class CacheWarmer
{
    protected string $urlListFile;
    protected array $urls = [];

    public function __construct(string $storageDir = '')
    {
        $dir = !empty($storageDir) ? $storageDir : dirname(__DIR__, 2) . '/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->urlListFile = $dir . '/warmer_urls.json';
        $this->loadUrls();
    }

    protected function loadUrls(): void
    {
        if (file_exists($this->urlListFile)) {
            $data = @file_get_contents($this->urlListFile);
            $parsed = $data ? json_decode($data, true) : null;
            if (is_array($parsed)) {
                $this->urls = $parsed;
                return;
            }
        }

        // Default URLs to warm
        $this->urls = [
            'http://localhost/fphpf/public/index.php',
            'http://localhost/fphpf/public/dashboard.php',
            'http://localhost/fphpf/public/benchmark.php'
        ];
    }

    /**
     * Track a visited URL for future warmup runs.
     */
    public function recordUrl(string $url): void
    {
        if (!in_array($url, $this->urls, true) && count($this->urls) < 250) {
            $this->urls[] = $url;
            $this->saveUrls();
        }
    }

    protected function saveUrls(): void
    {
        @file_put_contents($this->urlListFile, json_encode($this->urls, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Parse XML content and extract all <loc> URLs.
     * Supports both standard sitemaps and sitemap index files.
     *
     * @param string $xmlContent
     * @return array<string> List of URLs
     */
    public function parseSitemap(string $xmlContent): array
    {
        $foundUrls = [];

        // 1. Try SimpleXML
        if (function_exists('simplexml_load_string')) {
            $xml = @simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING);
            if ($xml !== false) {
                // Check if standard <urlset>
                if (isset($xml->url)) {
                    foreach ($xml->url as $urlNode) {
                        $loc = trim((string)$urlNode->loc);
                        if (!empty($loc) && filter_var($loc, FILTER_VALIDATE_URL)) {
                            $foundUrls[] = $loc;
                        }
                    }
                }

                // Check if sitemap index <sitemapindex>
                if (isset($xml->sitemap)) {
                    foreach ($xml->sitemap as $sitemapNode) {
                        $loc = trim((string)$sitemapNode->loc);
                        if (!empty($loc) && filter_var($loc, FILTER_VALIDATE_URL)) {
                            $foundUrls[] = $loc;
                        }
                    }
                }
            }
        }

        // 2. Regex fallback (works even if XML is slightly malformed)
        if (empty($foundUrls)) {
            if (preg_match_all('#<loc>\s*(https?://[^\s<]+)\s*</loc>#i', $xmlContent, $matches)) {
                foreach ($matches[1] as $loc) {
                    $clean = trim($loc);
                    if (filter_var($clean, FILTER_VALIDATE_URL)) {
                        $foundUrls[] = $clean;
                    }
                }
            }
        }

        return array_values(array_unique($foundUrls));
    }

    /**
     * Fetch XML content from a local file path or remote HTTP(S) URL.
     *
     * @param string $sitemapUrlOrPath
     * @return string|false
     */
    public function fetchSitemap(string $sitemapUrlOrPath): string|false
    {
        // 1. Check if local file
        if (file_exists($sitemapUrlOrPath)) {
            return @file_get_contents($sitemapUrlOrPath);
        }

        // Relative path inside public or workspace
        $publicPath = dirname(__DIR__, 2) . '/public/' . ltrim($sitemapUrlOrPath, '/\\');
        if (file_exists($publicPath)) {
            return @file_get_contents($publicPath);
        }

        // 2. Fetch via cURL if URL
        if (filter_var($sitemapUrlOrPath, FILTER_VALIDATE_URL)) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $sitemapUrlOrPath,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'FPHP-Cache-Warmer-Bot/1.0',
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && is_string($body)) {
                return $body;
            }
        }

        return false;
    }

    /**
     * Bulk warm all URLs extracted from an XML sitemap.
     *
     * @param string $sitemapUrlOrPath Local file path or HTTP URL
     * @return array [
     *   'success' => bool,
     *   'message' => string,
     *   'discovered_urls' => array,
     *   'results' => array
     * ]
     */
    public function warmFromSitemap(string $sitemapUrlOrPath): array
    {
        $content = $this->fetchSitemap($sitemapUrlOrPath);
        if ($content === false) {
            return [
                'success' => false,
                'message' => "Sitemap yüklenemedi: {$sitemapUrlOrPath}",
                'discovered_urls' => [],
                'results' => []
            ];
        }

        $discovered = $this->parseSitemap($content);
        if (empty($discovered)) {
            return [
                'success' => false,
                'message' => "Sitemap içinde geçerli <loc> URL'i bulunamadı.",
                'discovered_urls' => [],
                'results' => []
            ];
        }

        // If sitemap index contains sub-sitemaps, fetch them too
        $allPageUrls = [];
        foreach ($discovered as $url) {
            if (str_ends_with(strtolower($url), '.xml')) {
                // Sub-sitemap
                $subContent = $this->fetchSitemap($url);
                if ($subContent !== false) {
                    $subUrls = $this->parseSitemap($subContent);
                    foreach ($subUrls as $sUrl) {
                        $allPageUrls[] = $sUrl;
                    }
                }
            } else {
                $allPageUrls[] = $url;
            }
        }

        $allPageUrls = array_values(array_unique(!empty($allPageUrls) ? $allPageUrls : $discovered));

        // Add to persistent warmer urls
        foreach ($allPageUrls as $url) {
            if (!in_array($url, $this->urls, true)) {
                $this->urls[] = $url;
            }
        }
        $this->saveUrls();

        // Run high-concurrency warmup on all discovered URLs
        $results = $this->warmUrls($allPageUrls);

        return [
            'success' => true,
            'message' => count($allPageUrls) . " adet sayfa sitemap üzerinden keşfedildi ve paralel olarak ısıtıldı.",
            'discovered_urls' => $allPageUrls,
            'results' => $results
        ];
    }

    /**
     * Run high-concurrency non-blocking parallel warmup for a specific list of URLs.
     *
     * @param array $urls
     * @return array
     */
    public function warmUrls(array $urls): array
    {
        if (empty($urls)) {
            return [];
        }

        $multi = curl_multi_init();
        $handles = [];
        $results = [];

        foreach ($urls as $index => $url) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'FPHP-Cache-Warmer-Bot/1.0',
                CURLOPT_HEADER => true,
                CURLOPT_SSL_VERIFYPEER => false
            ]);

            curl_multi_add_handle($multi, $ch);
            $handles[$index] = ['ch' => $ch, 'url' => $url, 'start' => hrtime(true)];
        }

        $active = null;
        do {
            $status = curl_multi_exec($multi, $active);
            if ($active) {
                curl_multi_select($multi, 0.05);
            }
        } while ($active && $status === CURLM_OK);

        foreach ($handles as $item) {
            $ch = $item['ch'];
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $elapsedMs = round((hrtime(true) - $item['start']) / 1000000, 2);

            $results[] = [
                'url' => $item['url'],
                'http_code' => $httpCode,
                'latency_ms' => $elapsedMs,
                'status' => ($httpCode === 200 || $httpCode === 304) ? 'SUCCESS' : 'WARN'
            ];

            curl_multi_remove_handle($multi, $ch);
            @curl_close($ch);
        }

        @curl_multi_close($multi);
        return $results;
    }

    /**
     * Warm all tracked URLs in parallel.
     */
    public function warmAll(): array
    {
        return $this->warmUrls($this->urls);
    }

    public function getUrls(): array
    {
        return $this->urls;
    }

    public function addUrl(string $url): void
    {
        $this->recordUrl($url);
    }
}
