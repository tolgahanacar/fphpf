<?php

declare(strict_types=1);

namespace Fphp\Driver;

interface CacheDriverInterface
{
    /**
     * Retrieve an item from the cache.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Store an item in the cache with a time-to-live.
     *
     * @param string $key
     * @param mixed $value
     * @param int $ttl Time-to-live in seconds
     * @return bool
     */
    public function set(string $key, mixed $value, int $ttl = 3600): bool;

    /**
     * Determine if an item exists and is not expired.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Delete an item from the cache.
     *
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool;

    /**
     * Clear all cached items in this driver.
     *
     * @return bool
     */
    public function clear(): bool;

    /**
     * Check if this driver is supported in the current environment.
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * Get diagnostic and usage statistics.
     *
     * @return array
     */
    public function getStats(): array;

    /**
     * Get driver identifier name.
     *
     * @return string
     */
    public function getName(): string;
}
