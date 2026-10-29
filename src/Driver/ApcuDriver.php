<?php

declare(strict_types=1);

namespace Fphp\Driver;

/**
 * APCu In-Memory Shared Memory Driver
 * 
 * Provides ultra-low latency cache access directly in PHP worker memory (1 - 5 microseconds).
 * Eliminates all network overhead (unlike Redis) and filesystem I/O.
 */
class ApcuDriver implements CacheDriverInterface
{
    protected string $prefix;

    public function __construct(string $prefix = 'fphp:')
    {
        $this->prefix = $prefix;
    }

    public function isAvailable(): bool
    {
        if (!function_exists('apcu_fetch')) {
            return false;
        }

        if (PHP_SAPI === 'cli') {
            return (bool) ini_get('apc.enable_cli');
        }

        return (bool) ini_get('apc.enabled');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->isAvailable()) {
            return $default;
        }

        $success = false;
        $value = apcu_fetch($this->prefix . $key, $success);

        return $success ? $value : $default;
    }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        return apcu_store($this->prefix . $key, $value, $ttl);
    }

    public function has(string $key): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        return apcu_exists($this->prefix . $key);
    }

    public function delete(string $key): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        return apcu_delete($this->prefix . $key);
    }

    public function clear(): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        // Try clearing matching user keys first
        if (class_exists('APCUIterator', false)) {
            $iterator = new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '/');
            return apcu_delete($iterator);
        }

        return apcu_clear_cache();
    }

    /**
     * Delete all keys starting with given prefix.
     */
    public function deletePrefix(string $prefix): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $fullPrefix = $this->prefix . $prefix;
        if (class_exists('APCUIterator', false)) {
            $iterator = new \APCUIterator('/^' . preg_quote($fullPrefix, '/') . '/');
            $count = $iterator->getTotalCount();
            apcu_delete($iterator);
            return $count;
        }

        return 0;
    }

    public function getStats(): array
    {
        if (!$this->isAvailable()) {
            return [
                'available' => false,
                'name' => 'apcu',
                'status' => 'Disabled or not installed'
            ];
        }

        $sma = @apcu_sma_info(true) ?: [];
        $info = @apcu_cache_info(true) ?: [];

        $totalMem = $sma['num_seg'] ?? 0 ? ($sma['num_seg'] * ($sma['seg_size'] ?? 0)) : 0;
        $availMem = $sma['avail_mem'] ?? 0;
        $usedMem = max(0, $totalMem - $availMem);

        return [
            'available' => true,
            'name' => 'apcu',
            'type' => 'In-Memory Shared Memory (RAM)',
            'latency' => '1 - 5 µs (Microseconds)',
            'num_entries' => $info['num_entries'] ?? 0,
            'num_hits' => $info['num_hits'] ?? 0,
            'num_misses' => $info['num_misses'] ?? 0,
            'hit_ratio' => ($info['num_hits'] ?? 0) + ($info['num_misses'] ?? 0) > 0
                ? round((($info['num_hits'] ?? 0) / (($info['num_hits'] ?? 0) + ($info['num_misses'] ?? 0))) * 100, 2) . '%'
                : '0%',
            'memory_used_mb' => round($usedMem / 1024 / 1024, 2),
            'memory_total_mb' => round($totalMem / 1024 / 1024, 2),
            'memory_free_mb' => round($availMem / 1024 / 1024, 2)
        ];
    }

    public function getName(): string
    {
        return 'apcu';
    }
}
