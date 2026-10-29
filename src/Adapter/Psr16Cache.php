<?php

declare(strict_types=1);

namespace Fphp\Adapter;

use Fphp\Driver\CacheDriverInterface;
use Fphp\FphpCache;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as PsrSimpleCacheInvalidArgumentException;

require_once __DIR__ . '/PsrShims.php';
class Psr16InvalidArgumentException extends \InvalidArgumentException implements PsrSimpleCacheInvalidArgumentException {}

/**
 * Enterprise PSR-16 (Simple Cache) Compliant Adapter for FPHP Cache
 * 
 * Allows FPHP Cache to be used as a drop-in cache engine in Symfony, Laravel,
 * Laminas, and any framework expecting a PSR-16 CacheInterface.
 */
class Psr16Cache implements CacheInterface
{
    protected CacheDriverInterface $driver;
    protected int $defaultTtl;

    public function __construct(?CacheDriverInterface $driver = null, int $defaultTtl = 3600)
    {
        $this->driver = $driver ?? FphpCache::getDriver();
        $this->defaultTtl = $defaultTtl;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        return $this->driver->get($key, $default);
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        $seconds = $this->normalizeTtl($ttl);
        if ($seconds <= 0) {
            return $this->driver->delete($key);
        }
        return $this->driver->set($key, $value, $seconds);
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        return $this->driver->delete($key);
    }

    public function clear(): bool
    {
        return $this->driver->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $results = [];
        foreach ($keys as $key) {
            $this->validateKey($key);
            $results[$key] = $this->driver->get($key, $default);
        }
        return $results;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $seconds = $this->normalizeTtl($ttl);
        $success = true;

        foreach ($values as $key => $value) {
            $this->validateKey((string)$key);
            if ($seconds <= 0) {
                if (!$this->driver->delete((string)$key)) {
                    $success = false;
                }
            } else {
                if (!$this->driver->set((string)$key, $value, $seconds)) {
                    $success = false;
                }
            }
        }

        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            $this->validateKey((string)$key);
            if (!$this->driver->delete((string)$key)) {
                $success = false;
            }
        }
        return $success;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);
        return $this->driver->has($key);
    }

    protected function validateKey(mixed $key): void
    {
        if (!is_string($key)) {
            throw new Psr16InvalidArgumentException("Cache key must be a string.");
        }

        if ($key === '') {
            throw new Psr16InvalidArgumentException("Cache key cannot be empty.");
        }

        if (preg_match('/[{}()\/\\\\@:]/', $key)) {
            throw new Psr16InvalidArgumentException("Invalid character in cache key '{$key}'. PSR-16 reserves {}()/\@:");
        }
    }

    protected function normalizeTtl(null|int|\DateInterval $ttl): int
    {
        if ($ttl === null) {
            return $this->defaultTtl;
        }

        if ($ttl instanceof \DateInterval) {
            $now = new \DateTimeImmutable();
            return (int)($now->add($ttl)->getTimestamp() - $now->getTimestamp());
        }

        return (int)$ttl;
    }

    public function getDriver(): CacheDriverInterface
    {
        return $this->driver;
    }
}
