<?php

declare(strict_types=1);

namespace Fphp\Adapter;

use Fphp\Driver\CacheDriverInterface;
use Fphp\FphpCache;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException as PsrCacheInvalidArgumentException;

require_once __DIR__ . '/PsrShims.php';

class Psr6InvalidArgumentException extends \InvalidArgumentException implements PsrCacheInvalidArgumentException {}

/**
 * Enterprise PSR-6 (CacheItemPool) Compliant Adapter for FPHP Cache
 */
class Psr6CachePool implements CacheItemPoolInterface
{
    protected CacheDriverInterface $driver;
    protected array $deferred = [];
    protected int $defaultTtl;

    public function __construct(?CacheDriverInterface $driver = null, int $defaultTtl = 3600)
    {
        $this->driver = $driver ?? FphpCache::getDriver();
        $this->defaultTtl = $defaultTtl;
    }

    public function getItem(string $key): CacheItemInterface
    {
        $this->validateKey($key);

        if (array_key_exists($key, $this->deferred)) {
            $item = $this->deferred[$key];
            return new Psr6CacheItem($key, $item->getRawValue(), true);
        }

        $val = $this->driver->get($key);
        $isHit = ($val !== null);

        return new Psr6CacheItem($key, $val, $isHit);
    }

    public function getItems(array $keys = []): iterable
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }
        return $items;
    }

    public function hasItem(string $key): bool
    {
        $this->validateKey($key);

        if (array_key_exists($key, $this->deferred)) {
            return true;
        }

        return $this->driver->has($key);
    }

    public function clear(): bool
    {
        $this->deferred = [];
        return $this->driver->clear();
    }

    public function deleteItem(string $key): bool
    {
        $this->validateKey($key);
        unset($this->deferred[$key]);
        return $this->driver->delete($key);
    }

    public function deleteItems(array $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            if (!$this->deleteItem($key)) {
                $success = false;
            }
        }
        return $success;
    }

    public function save(CacheItemInterface $item): bool
    {
        $key = $item->getKey();
        $this->validateKey($key);

        $ttl = ($item instanceof Psr6CacheItem) ? $item->getTtl() : null;
        $seconds = $ttl !== null ? $ttl : $this->defaultTtl;

        if ($seconds <= 0) {
            return $this->driver->delete($key);
        }

        return $this->driver->set($key, $item->get(), $seconds);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        $this->validateKey($item->getKey());
        $this->deferred[$item->getKey()] = $item;
        return true;
    }

    public function commit(): bool
    {
        $success = true;
        foreach ($this->deferred as $item) {
            if (!$this->save($item)) {
                $success = false;
            }
        }
        $this->deferred = [];
        return $success;
    }

    public function __destruct()
    {
        $this->commit();
    }

    protected function validateKey(mixed $key): void
    {
        if (!is_string($key)) {
            throw new Psr6InvalidArgumentException("Cache key must be a string.");
        }

        if ($key === '') {
            throw new Psr6InvalidArgumentException("Cache key cannot be empty.");
        }

        if (preg_match('/[{}()\/\\\\@:]/', $key)) {
            throw new Psr6InvalidArgumentException("Invalid character in cache key '{$key}'. PSR-6 reserves {}()/\@:");
        }
    }
}
