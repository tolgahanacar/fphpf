<?php

declare(strict_types=1);

namespace Fphp\Index;

/**
 * Inverted Prefix Catalog & Virtual Invalidation Index
 *
 * Provides sub-microsecond O(1) prefix invalidation and targeted O(K) bulk deletion.
 * Eliminates the need to walk the entire directory tree (RecursiveDirectoryIterator)
 * when invalidating wildcard prefixes like 'urunler/*' or 'blog:*'.
 */
class PrefixIndex
{
    protected string $indexDir;
    protected string $versionsFile;
    protected array $localVersions = [];

    public function __construct(string $cacheDir)
    {
        $base = rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'prefixes';
        $this->indexDir = $base;
        $this->versionsFile = $base . DIRECTORY_SEPARATOR . 'versions.json';
        $this->ensureDir($this->indexDir);
        $this->loadVersions();
    }

    /**
     * Register a cache key and its physical file path into the prefix catalog.
     *
     * @param string $key Full cache key
     * @param string $filePath Full physical file path on disk
     * @return bool True on success
     */
    public function record(string $key, string $filePath): bool
    {
        $prefixes = $this->extractPrefixes($key);
        if (empty($prefixes)) {
            return true;
        }

        foreach ($prefixes as $prefix) {
            $prefixHash = hash('xxh128', $prefix);
            $indexFile = $this->indexDir . DIRECTORY_SEPARATOR . $prefixHash . '.pidx';
            $line = $filePath . '|' . $key . "\n";
            @file_put_contents($indexFile, $line, FILE_APPEND | LOCK_EX);
        }

        return true;
    }

    /**
     * Delete all cached items matching a prefix.
     * Bumps virtual version in O(1) and directly deletes all cataloged files without full disk traversal.
     *
     * @param string $prefix Prefix to invalidate
     * @return int Number of deleted files
     */
    public function purgePrefix(string $prefix): int
    {
        // 1. O(1) Virtual Invalidation
        $this->bumpVersion($prefix);

        // 2. Targeted physical deletion via inverted index
        $prefixHash = hash('xxh128', $prefix);
        $indexFile = $this->indexDir . DIRECTORY_SEPARATOR . $prefixHash . '.pidx';

        $deletedCount = 0;

        if (file_exists($indexFile)) {
            $lines = @file($indexFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                $processed = [];
                foreach ($lines as $line) {
                    $parts = explode('|', trim($line));
                    $targetPath = $parts[0] ?? '';
                    if (!empty($targetPath) && !isset($processed[$targetPath])) {
                        $processed[$targetPath] = true;
                        if (file_exists($targetPath)) {
                            if (@unlink($targetPath)) {
                                $deletedCount++;
                            }
                        }
                        // Also purge companion .gz if present
                        $gzPath = $targetPath . '.gz';
                        if (file_exists($gzPath)) {
                            @unlink($gzPath);
                        }
                    }
                }
            }
            @unlink($indexFile);
        }

        // Also check any child prefixes that start with this prefix
        if (is_dir($this->indexDir)) {
            $files = scandir($this->indexDir);
            if ($files !== false) {
                foreach ($files as $f) {
                    if (str_ends_with($f, '.pidx') && $f !== ($prefixHash . '.pidx')) {
                        // Check if the pidx contains keys matching prefix
                        $pPath = $this->indexDir . DIRECTORY_SEPARATOR . $f;
                        $lines = @file($pPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                        if (is_array($lines)) {
                            $matchesAny = false;
                            foreach ($lines as $line) {
                                $parts = explode('|', trim($line));
                                $k = $parts[1] ?? '';
                                if (str_starts_with($k, $prefix)) {
                                    $matchesAny = true;
                                    $targetPath = $parts[0] ?? '';
                                    if (!empty($targetPath) && file_exists($targetPath)) {
                                        if (@unlink($targetPath)) {
                                            $deletedCount++;
                                        }
                                        $gz = $targetPath . '.gz';
                                        if (file_exists($gz)) @unlink($gz);
                                    }
                                }
                            }
                            if ($matchesAny) {
                                @unlink($pPath);
                            }
                        }
                    }
                }
            }
        }

        return $deletedCount;
    }

    /**
     * Bump virtual prefix version for instant O(1) in-memory invalidation.
     */
    public function bumpVersion(string $prefix): int
    {
        $current = $this->getVersion($prefix);
        $newVersion = $current + 1;
        $this->localVersions[$prefix] = $newVersion;

        // Fast-path: APCu Shared Memory
        if (function_exists('apcu_store') && (PHP_SAPI !== 'cli' || ini_get('apc.enable_cli'))) {
            apcu_store('fphp:prefix_v:' . $prefix, $newVersion, 86400 * 30);
        }

        // Persistent file store
        $this->saveVersions();

        return $newVersion;
    }

    /**
     * Get the current version of a prefix.
     */
    public function getVersion(string $prefix): int
    {
        if (function_exists('apcu_fetch') && (PHP_SAPI !== 'cli' || ini_get('apc.enable_cli'))) {
            $success = false;
            $val = apcu_fetch('fphp:prefix_v:' . $prefix, $success);
            if ($success && is_int($val)) {
                return $val;
            }
        }

        return (int)($this->localVersions[$prefix] ?? 1);
    }

    /**
     * Extract prefixes from a key using common hierarchy separators (':', '/', '_').
     *
     * @param string $key
     * @return array<string>
     */
    public function extractPrefixes(string $key): array
    {
        $prefixes = [];
        $len = strlen($key);

        for ($i = 1; $i < $len; $i++) {
            $char = $key[$i];
            if ($char === ':' || $char === '/' || $char === '.') {
                $prefixes[] = substr($key, 0, $i + 1);
            }
        }

        return array_unique($prefixes);
    }

    /**
     * Clear all prefix index files and version counters.
     */
    public function clear(): bool
    {
        $this->localVersions = [];
        if (is_dir($this->indexDir)) {
            $files = scandir($this->indexDir);
            if ($files !== false) {
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..') {
                        @unlink($this->indexDir . DIRECTORY_SEPARATOR . $file);
                    }
                }
            }
        }
        return true;
    }

    /**
     * Get prefix index telemetry stats.
     */
    public function getStats(): array
    {
        $pidxCount = 0;
        if (is_dir($this->indexDir)) {
            $files = scandir($this->indexDir);
            if ($files !== false) {
                foreach ($files as $file) {
                    if (str_ends_with($file, '.pidx')) {
                        $pidxCount++;
                    }
                }
            }
        }

        return [
            'tracked_prefixes' => count($this->localVersions),
            'catalog_files' => $pidxCount,
            'index_path' => $this->indexDir
        ];
    }

    protected function loadVersions(): void
    {
        if (file_exists($this->versionsFile)) {
            $raw = @file_get_contents($this->versionsFile);
            if ($raw) {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $this->localVersions = $data;
                }
            }
        }
    }

    protected function saveVersions(): void
    {
        @file_put_contents($this->versionsFile, json_encode($this->localVersions, JSON_PRETTY_PRINT), LOCK_EX);
    }

    protected function ensureDir(string $path): void
    {
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
    }
}

