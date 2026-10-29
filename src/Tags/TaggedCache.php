<?php

declare(strict_types=1);

namespace Fphp\Tags;

use Fphp\Driver\CacheDriverInterface;

/**
 * Tagged Cache Proxy
 * 
 * Provides tag-scoped caching operations with O(1) version clocks.
 */
class TaggedCache
{
    protected CacheDriverInterface $driver;
    protected TagManager $tagManager;
    protected array $tags;

    public function __construct(CacheDriverInterface $driver, TagManager $tagManager, array $tags)
    {
        $this->driver = $driver;
        $this->tagManager = $tagManager;
        $this->tags = $tags;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $payload = $this->driver->get($key);
        if ($payload === null || !is_array($payload) || !isset($payload['__fphp_val'])) {
            return $default;
        }

        // Validate tag versions
        $itemTagVersions = $payload['__fphp_tags'] ?? [];
        if (!$this->tagManager->areVersionsValid($itemTagVersions)) {
            $this->driver->delete($key);
            return $default;
        }

        return $payload['__fphp_val'];
    }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        $currentVersions = $this->tagManager->getTagVersions($this->tags);

        $payload = [
            '__fphp_val' => $value,
            '__fphp_tags' => $currentVersions,
            '__fphp_time' => time()
        ];

        return $this->driver->set($key, $payload, $ttl);
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $val = $this->get($key);
        if ($val !== null) {
            return $val;
        }

        $computed = $callback();
        $this->set($key, $computed, $ttl);
        return $computed;
    }

    /**
     * Probabilistic Early Expiration (XFetch) for Tagged Items.
     */
    public function rememberXFetch(string $key, int $ttl, callable $callback, float $beta = 1.0): mixed
    {
        $payload = $this->driver->get($key);
        $now = microtime(true);

        if (
            is_array($payload) &&
            isset($payload['__fphp_val']) &&
            is_array($payload['__fphp_val']) &&
            isset($payload['__fphp_val']['__xfetch'])
        ) {
            // Check tag validity
            $itemTagVersions = $payload['__fphp_tags'] ?? [];
            if ($this->tagManager->areVersionsValid($itemTagVersions)) {
                $xfetchData = $payload['__fphp_val'];
                $delta = (float)($xfetchData['delta'] ?? 0.05);
                $expiry = (float)($xfetchData['expires_at'] ?? 0);
                $remaining = $expiry - $now;

                $u = max(0.000001, (float)mt_rand(1, 1000000) / 1000000.0);
                $x = - $beta * $delta * log($u);

                if ($remaining > $x) {
                    return $xfetchData['val'];
                }

                $lockKey = 'xfetch:tag:' . $key;
                $hasLock = \Fphp\Lock\AtomicLock::acquire($lockKey, 5);

                if (!$hasLock) {
                    return $xfetchData['val'];
                }

                try {
                    $t0 = microtime(true);
                    $newVal = $callback();
                    $execDelta = max(0.001, microtime(true) - $t0);

                    $wrapped = [
                        'val' => $newVal,
                        'delta' => $execDelta,
                        'expires_at' => microtime(true) + $ttl,
                        '__xfetch' => true
                    ];

                    $this->set($key, $wrapped, $ttl + 300);
                    return $newVal;
                } finally {
                    \Fphp\Lock\AtomicLock::release($lockKey);
                }
            }
        }

        $lockKey = 'xfetch:tag:' . $key;
        $hasLock = \Fphp\Lock\AtomicLock::acquire($lockKey, 8);

        if (!$hasLock) {
            for ($i = 0; $i < 25; $i++) {
                usleep(2000);
                $val = $this->get($key);
                if (is_array($val) && isset($val['__xfetch'])) {
                    return $val['val'];
                }
            }
        }

        try {
            $t0 = microtime(true);
            $computed = $callback();
            $execDelta = max(0.001, microtime(true) - $t0);

            $wrapped = [
                'val' => $computed,
                'delta' => $execDelta,
                'expires_at' => microtime(true) + $ttl,
                '__xfetch' => true
            ];

            $this->set($key, $wrapped, $ttl + 300);
            return $computed;
        } finally {
            if ($hasLock) {
                \Fphp\Lock\AtomicLock::release($lockKey);
            }
        }
    }

    public function flush(): void
    {
        $this->tagManager->invalidate($this->tags);
    }
}
