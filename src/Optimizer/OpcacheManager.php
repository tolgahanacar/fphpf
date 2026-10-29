<?php

declare(strict_types=1);

namespace Fphp\Optimizer;

/**
 * Enterprise OPcache & JIT (Just-In-Time) Optimizer & Preloader Generator
 * 
 * Analyzes PHP bytecode cache status and generates automated preloading scripts
 * for zero-overhead runtime class execution.
 */
class OpcacheManager
{
    /**
     * Get detailed OPcache & JIT diagnostics.
     *
     * @return array
     */
    public static function getDiagnostics(): array
    {
        $enabled = function_exists('opcache_get_status') && (bool)ini_get('opcache.enable');
        $status = $enabled ? @opcache_get_status(false) : false;
        $config = function_exists('opcache_get_configuration') ? @opcache_get_configuration() : [];

        $jitEnabled = false;
        $jitBuffer = ini_get('opcache.jit_buffer_size');
        $jitMode = ini_get('opcache.jit');

        if ($status && isset($status['jit']['enabled'])) {
            $jitEnabled = (bool)$status['jit']['enabled'];
        } elseif (!empty($jitBuffer) && $jitBuffer !== '0' && $jitBuffer !== '0M') {
            $jitEnabled = true;
        }

        $memUsedMb = 0.0;
        $memFreeMb = 0.0;
        $hitRate = 0.0;
        $cachedScripts = 0;

        if ($status && isset($status['memory_usage'])) {
            $memUsedMb = round($status['memory_usage']['used_memory'] / 1024 / 1024, 2);
            $memFreeMb = round($status['memory_usage']['free_memory'] / 1024 / 1024, 2);
            $hitRate = round($status['opcache_statistics']['opcache_hit_rate'] ?? 0, 1);
            $cachedScripts = $status['opcache_statistics']['num_cached_scripts'] ?? 0;
        }

        return [
            'opcache_enabled' => $enabled,
            'opcache_version' => $config['version']['version'] ?? 'PHP ' . PHP_VERSION,
            'hit_rate' => $hitRate,
            'memory_used_mb' => $memUsedMb,
            'memory_free_mb' => $memFreeMb,
            'cached_scripts' => $cachedScripts,
            'jit_enabled' => $jitEnabled,
            'jit_buffer_size' => $jitBuffer ?: '0M',
            'jit_mode' => $jitMode ?: 'Disabled',
            'recommendations' => self::generateRecommendations($enabled, $jitEnabled, $hitRate)
        ];
    }

    /**
     * Generate optimal recommendations.
     */
    protected static function generateRecommendations(bool $opcacheEnabled, bool $jitEnabled, float $hitRate): array
    {
        $recs = [];

        if (!$opcacheEnabled) {
            $recs[] = 'Zend OPcache devre dışı. php.ini dosyasında "opcache.enable=1" ayarını etkinleştirin.';
        }

        if (!$jitEnabled) {
            $recs[] = 'PHP JIT (Just-In-Time) kapalı. php.ini içine "opcache.jit_buffer_size=128M" ve "opcache.jit=tracing" ekleyerek CPU hızını x2 katına çıkarabilirsiniz.';
        } else {
            $recs[] = 'JIT (Just-In-Time) Derleyicisi aktif! Bytecode doğrudan makine koduna dönüştürülüyor.';
        }

        if ($hitRate < 90 && $opcacheEnabled) {
            $recs[] = 'OPcache dosya sayısı limitini artırın: "opcache.max_accelerated_files=30000".';
        }

        return $recs;
    }

    /**
     * Generate standalone preload.php script for PHP opcache.preload.
     *
     * @param string $outputFilePath
     * @return bool
     */
    public static function generatePreloadFile(string $outputFilePath): bool
    {
        $srcDir = dirname(__DIR__);
        $filesToPreload = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $filesToPreload[] = realpath($file->getPathname());
            }
        }

        $code = "<?php\n/**\n * FPHP Cache - OPcache Preloading Script\n * Add to php.ini: opcache.preload = \"" . addslashes($outputFilePath) . "\"\n */\n\n";
        $code .= "\$files = " . var_export($filesToPreload, true) . ";\n\n";
        $code .= "foreach (\$files as \$file) {\n";
        $code .= "    if (file_exists(\$file)) {\n";
        $code .= "        opcache_compile_file(\$file);\n";
        $code .= "    }\n";
        $code .= "}\n";

        return (bool)@file_put_contents($outputFilePath, $code, LOCK_EX);
    }
}
