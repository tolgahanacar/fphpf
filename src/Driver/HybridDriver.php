<?php

declare(strict_types=1);

namespace Fphp\Driver;

/**
 * Multi-Tier Hybrid Cache Driver
 * 
 * Hierarchy:
 * - L0: In-Process Memory (0.1 - 0.5 µs)
 * - L1: Shared Memory APCu (1 - 5 µs)
 * - L2: Atomic NVMe/SSD File System (50 - 150 µs)
 * 
 * Provides instantaneous in-memory performance with reliable disk persistence.
 */
class HybridDriver implements CacheDriverInterface
{
    protected MemoryDriver $l0;
    protected ?ApcuDriver $l1 = null;
    protected ?RedisDriver $redis = null;
    protected FileDriver $l2;

    public function __construct(
        string $cachePath = '',
        string $prefix = 'fphp:',
        bool $useCompression = true,
        ?RedisDriver $redis = null
    ) {
        $this->l0 = new MemoryDriver();

        $apcu = new ApcuDriver($prefix);
        if ($apcu->isAvailable()) {
            $this->l1 = $apcu;
        }

        $this->redis = ($redis !== null && $redis->isAvailable()) ? $redis : null;
        $this->l2 = new FileDriver($cachePath, $prefix, $useCompression);
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // 1. Check L0 (Per-request memory)
        if ($this->l0->has($key)) {
            return $this->l0->get($key);
        }

        // 2. Check L1 (APCu shared memory)
        if ($this->l1 !== null) {
            $val = $this->l1->get($key, null);
            if ($val !== null) {
                $this->l0->set($key, $val, 60);
                return $val;
            }
        }

        // 3. Check Remote Redis Cluster (if enabled)
        if ($this->redis !== null) {
            $val = $this->redis->get($key, null);
            if ($val !== null) {
                if ($this->l1 !== null) {
                    $this->l1->set($key, $val, 3600);
                }
                $this->l0->set($key, $val, 60);
                $this->l2->set($key, $val, 3600);
                return $val;
            }
        }

        // 4. Check L2 (Atomic File cache)
        $val = $this->l2->get($key, null);
        if ($val !== null) {
            // Warm up L1, L0, and Redis
            if ($this->l1 !== null) {
                $this->l1->set($key, $val, 3600);
            }
            if ($this->redis !== null) {
                $this->redis->set($key, $val, 3600);
            }
            $this->l0->set($key, $val, 60);
            return $val;
        }

        return $default;
    }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        $this->l0->set($key, $value, min($ttl, 60));

        if ($this->l1 !== null) {
            $this->l1->set($key, $value, $ttl);
        }

        if ($this->redis !== null) {
            $this->redis->set($key, $value, $ttl);
        }

        return $this->l2->set($key, $value, $ttl);
    }

    public function has(string $key): bool
    {
        if ($this->l0->has($key)) {
            return true;
        }

        if ($this->l1 !== null && $this->l1->has($key)) {
            return true;
        }

        if ($this->redis !== null && $this->redis->has($key)) {
            return true;
        }

        return $this->l2->has($key);
    }

    public function delete(string $key): bool
    {
        $this->l0->delete($key);
        if ($this->l1 !== null) {
            $this->l1->delete($key);
        }
        if ($this->redis !== null) {
            $this->redis->delete($key);
        }
        return $this->l2->delete($key);
    }

    public function clear(): bool
    {
        $this->l0->clear();
        if ($this->l1 !== null) {
            $this->l1->clear();
        }
        if ($this->redis !== null) {
            $this->redis->clear();
        }
        return $this->l2->clear();
    }

    public function prune(int $maxAge = 0): array
    {
        return $this->l2->prune($maxAge);
    }

    /**
     * Cascade delete items matching prefix across all hybrid tiers.
     */
    public function deletePrefix(string $prefix): int
    {
        $this->l0->deletePrefix($prefix);
        if ($this->l1 !== null) {
            $this->l1->deletePrefix($prefix);
        }
        if ($this->redis !== null) {
            $this->redis->deletePrefix($prefix);
        }
        return $this->l2->deletePrefix($prefix);
    }

    public function getStats(): array
    {
        return [
            'available' => true,
            'name' => 'hybrid',
            'type' => 'Multi-Tier Hybrid (L0 Memory + L1 APCu + ' . ($this->redis ? 'Redis + ' : '') . 'L2 SSD File)',
            'latency' => $this->l1 !== null ? '1 - 10 µs (RAM Hit)' : ($this->redis ? '0.5 - 2 ms (Redis)' : '50 - 150 µs (SSD Hit)'),
            'has_apcu' => $this->l1 !== null,
            'has_redis' => $this->redis !== null,
            'l0_stats' => $this->l0->getStats(),
            'l1_apcu_stats' => $this->l1 ? $this->l1->getStats() : ['available' => false],
            'redis_stats' => $this->redis ? $this->redis->getStats() : ['available' => false],
            'l2_file_stats' => $this->l2->getStats()
        ];
    }

    public function getName(): string
    {
        $parts = [];
        if ($this->l1 !== null) $parts[] = 'apcu';
        if ($this->redis !== null) $parts[] = 'redis';
        $parts[] = 'file';
        return 'hybrid(' . implode('+', $parts) . ')';
    }

    public function getL1Driver(): ?ApcuDriver
    {
        return $this->l1;
    }

    public function getRedisDriver(): ?RedisDriver
    {
        return $this->redis;
    }

    public function getL2Driver(): FileDriver
    {
        return $this->l2;
    }
}
