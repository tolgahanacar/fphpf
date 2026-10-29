<?php

declare(strict_types=1);

namespace Fphp\Driver;

/**
 * In-Request Array Memory Driver (L0 Cache)
 * 
 * Provides sub-microsecond in-process caching for repeatedly accessed objects
 * within the lifecycle of a single request.
 */
class MemoryDriver implements CacheDriverInterface
{
    protected array $storage = [];
    protected array $expirations = [];
    protected int $maxItems;

    public function __construct(int $maxItems = 5000)
    {
        $this->maxItems = max(1, $maxItems);
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!isset($this->storage[$key])) {
            return $default;
        }

        if (isset($this->expirations[$key]) && $this->expirations[$key] > 0 && time() > $this->expirations[$key]) {
            unset($this->storage[$key], $this->expirations[$key]);
            return $default;
        }

        // LRU bump: move accessed item to most recent position
        $val = $this->storage[$key];
        unset($this->storage[$key]);
        $this->storage[$key] = $val;

        return $val;
    }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        if (isset($this->storage[$key])) {
            unset($this->storage[$key]);
        } elseif (count($this->storage) >= $this->maxItems) {
            // O(1) LRU eviction: pop oldest unaccessed item
            $oldestKey = array_key_first($this->storage);
            if ($oldestKey !== null) {
                unset($this->storage[$oldestKey], $this->expirations[$oldestKey]);
            }
        }

        $this->storage[$key] = $value;
        $this->expirations[$key] = $ttl > 0 ? time() + $ttl : 0;
        return true;
    }

    public function has(string $key): bool
    {
        if (!isset($this->storage[$key])) {
            return false;
        }

        if (isset($this->expirations[$key]) && $this->expirations[$key] > 0 && time() > $this->expirations[$key]) {
            unset($this->storage[$key], $this->expirations[$key]);
            return false;
        }

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->storage[$key], $this->expirations[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->storage = [];
        $this->expirations = [];
        return true;
    }

    /**
     * Delete all keys starting with given prefix.
     */
    public function deletePrefix(string $prefix): int
    {
        $count = 0;
        foreach (array_keys($this->storage) as $key) {
            if (str_starts_with((string)$key, $prefix)) {
                unset($this->storage[$key], $this->expirations[$key]);
                $count++;
            }
        }
        return $count;
    }

    public function getStats(): array
    {
        return [
            'available' => true,
            'name' => 'memory',
            'type' => 'Per-Request Local Memory (PHP Array)',
            'latency' => '0.1 - 0.5 µs',
            'count' => count($this->storage)
        ];
    }

    public function getName(): string
    {
        return 'memory';
    }
}
