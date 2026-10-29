<?php

declare(strict_types=1);

namespace Fphp\Driver;

/**
 * Enterprise Remote Redis Cluster & Standalone Driver
 * 
 * Provides network-level caching for distributed/multi-server load balanced PHP environments.
 * Gracefully degrades if Redis is unavailable without throwing fatal errors.
 */
class RedisDriver implements CacheDriverInterface
{
    protected ?\Redis $client = null;
    protected string $host;
    protected int $port;
    protected ?string $password;
    protected int $database;
    protected float $timeout;
    protected string $prefix;
    protected bool $connected = false;
    protected ?string $lastError = null;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        ?string $password = null,
        int $database = 0,
        float $timeout = 1.5,
        string $prefix = 'fphp:'
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->password = $password;
        $this->database = $database;
        $this->timeout = $timeout;
        $this->prefix = $prefix;

        $this->connect();
    }

    protected function connect(): void
    {
        if (!class_exists('\\Redis')) {
            $this->lastError = 'PHP Redis extension (ext-redis) is not installed.';
            return;
        }

        try {
            $this->client = new \Redis();
            $ok = @$this->client->connect($this->host, $this->port, $this->timeout);
            if ($ok) {
                if (!empty($this->password)) {
                    @$this->client->auth($this->password);
                }
                if ($this->database > 0) {
                    @$this->client->select($this->database);
                }
                $this->connected = true;
            } else {
                $this->lastError = "Could not connect to Redis server at {$this->host}:{$this->port}";
            }
        } catch (\Throwable $e) {
            $this->connected = false;
            $this->lastError = $e->getMessage();
        }
    }

    public function isAvailable(): bool
    {
        return $this->connected && $this->client !== null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->isAvailable()) {
            return $default;
        }

        try {
            $val = $this->client->get($this->prefix . $key);
            if ($val === false) {
                return $default;
            }

            if (str_starts_with($val, 'fphp_raw:')) {
                return substr($val, 9);
            }

            $unserialized = @unserialize($val);
            return ($unserialized === false && $val !== serialize(false)) ? $val : $unserialized;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return $default;
        }
    }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $payload = is_string($value) ? ('fphp_raw:' . $value) : serialize($value);
            if ($ttl > 0) {
                return (bool)$this->client->setex($this->prefix . $key, $ttl, $payload);
            }
            return (bool)$this->client->set($this->prefix . $key, $payload);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    public function has(string $key): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            return (bool)$this->client->exists($this->prefix . $key);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function delete(string $key): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            return (bool)$this->client->del($this->prefix . $key);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function clear(): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            // Delete matching prefix keys
            $keys = $this->client->keys($this->prefix . '*');
            if (!empty($keys)) {
                $this->client->del($keys);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Delete all keys starting with given prefix.
     */
    public function deletePrefix(string $prefix): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        try {
            $keys = $this->client->keys($this->prefix . $prefix . '*');
            if (empty($keys)) {
                return 0;
            }
            return (int)$this->client->del($keys);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function getStats(): array
    {
        $info = [];
        if ($this->isAvailable()) {
            try {
                $info = @$this->client->info() ?: [];
            } catch (\Throwable $e) {}
        }

        return [
            'available' => $this->isAvailable(),
            'name' => 'redis',
            'type' => 'Remote Redis Cluster / Server',
            'host' => $this->host,
            'port' => $this->port,
            'latency' => '0.5 - 2 ms (Network / Socket)',
            'status' => $this->isAvailable() ? 'Connected' : ($this->lastError ?? 'Not Connected'),
            'used_memory_human' => $info['used_memory_human'] ?? '0B',
            'connected_clients' => $info['connected_clients'] ?? 0,
            'uptime_in_days' => $info['uptime_in_days'] ?? 0
        ];
    }

    public function getName(): string
    {
        return 'redis';
    }

    public function getClient(): ?\Redis
    {
        return $this->client;
    }
}
