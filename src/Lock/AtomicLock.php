<?php

declare(strict_types=1);

namespace Fphp\Lock;

/**
 * Microsecond Atomic Spinlock & Thundering Herd Protector
 * 
 * Uses hardware-level atomic operations (apcu_add) or atomic filesystem locks
 * to ensure only 1 worker regenerates expired cache during massive traffic spikes.
 */
class AtomicLock
{
    protected static array $activeFileHandles = [];
    protected static array $ownerTokens = [];
    protected static string $lockDir = '';

    public static function getLockDir(): string
    {
        if (empty(self::$lockDir)) {
            self::$lockDir = dirname(__DIR__, 2) . '/cache/locks';
            if (!is_dir(self::$lockDir)) {
                @mkdir(self::$lockDir, 0775, true);
            }
        }
        return self::$lockDir;
    }

    /**
     * Attempt to acquire an exclusive atomic lock.
     *
     * @param string $name Lock identifier
     * @param int $ttl Lock timeout in seconds (auto-expires to prevent deadlocks)
     * @return bool True if acquired, False if already held by another process
     */
    public static function acquire(string $name, int $ttl = 10): bool
    {
        $token = bin2hex(random_bytes(8));
        $lockKey = 'fphp_lock:' . hash('xxh128', $name);

        // 1. Try APCu Atomic Add (1 µs)
        if (function_exists('apcu_add') && (PHP_SAPI !== 'cli' || ini_get('apc.enable_cli'))) {
            $acquired = (bool) @apcu_add($lockKey, $token, $ttl);
            if ($acquired) {
                self::$ownerTokens[$name] = $token;
                return true;
            }
            return false;
        }

        // 2. Fallback: Atomic Filesystem Lock (O_CREAT | O_EXCL)
        $lockFile = self::getLockDir() . DIRECTORY_SEPARATOR . hash('xxh128', $name) . '.lock';

        // Clean expired lock file if any
        if (file_exists($lockFile)) {
            $mtime = @filemtime($lockFile);
            if ($mtime !== false && (time() - $mtime) > $ttl) {
                @unlink($lockFile);
            }
        }

        // 'x' flag opens for exclusive creation atomically
        $handle = @fopen($lockFile, 'x');
        if ($handle === false) {
            return false;
        }

        @fwrite($handle, $token . ':' . (time() + $ttl));
        self::$activeFileHandles[$name] = $handle;
        self::$ownerTokens[$name] = $token;
        return true;
    }

    /**
     * Block / Spinlock: Wait until lock is acquired or timeout expires.
     *
     * @param string $name Lock identifier
     * @param int $ttl Lock lifetime once acquired (seconds)
     * @param int $timeoutMs Maximum time to wait for lock in milliseconds
     * @param int $sleepUs Microseconds to sleep between retries (default 2000 µs = 2ms)
     * @return bool True if acquired within timeout, False if timed out
     */
    public static function block(string $name, int $ttl = 10, int $timeoutMs = 500, int $sleepUs = 2000): bool
    {
        $deadline = microtime(true) + ($timeoutMs / 1000.0);

        while (microtime(true) < $deadline) {
            if (self::acquire($name, $ttl)) {
                return true;
            }
            usleep($sleepUs);
        }

        return false;
    }

    /**
     * Release previously acquired lock.
     * Only releases if held by the current process/token (deadlock & theft protection).
     *
     * @param string $name
     * @return bool
     */
    public static function release(string $name): bool
    {
        $lockKey = 'fphp_lock:' . hash('xxh128', $name);
        $token = self::$ownerTokens[$name] ?? null;

        if (function_exists('apcu_delete') && (PHP_SAPI !== 'cli' || ini_get('apc.enable_cli'))) {
            $success = false;
            $currentVal = @apcu_fetch($lockKey, $success);
            if ($success && ($token === null || $currentVal === $token)) {
                @apcu_delete($lockKey);
            }
        }

        if (isset(self::$activeFileHandles[$name])) {
            @fclose(self::$activeFileHandles[$name]);
            unset(self::$activeFileHandles[$name]);
        }

        $lockFile = self::getLockDir() . DIRECTORY_SEPARATOR . hash('xxh128', $name) . '.lock';
        if (file_exists($lockFile)) {
            if ($token !== null) {
                $content = (string)@file_get_contents($lockFile);
                if (str_starts_with($content, $token)) {
                    @unlink($lockFile);
                }
            } else {
                @unlink($lockFile);
            }
        }

        unset(self::$ownerTokens[$name]);
        return true;
    }

    /**
     * Check if lock is currently held.
     *
     * @param string $name
     * @return bool
     */
    public static function isLocked(string $name): bool
    {
        $lockKey = 'fphp_lock:' . hash('xxh128', $name);

        if (function_exists('apcu_exists') && (PHP_SAPI !== 'cli' || ini_get('apc.enable_cli'))) {
            return (bool) @apcu_exists($lockKey);
        }

        $lockFile = self::getLockDir() . DIRECTORY_SEPARATOR . hash('xxh128', $name) . '.lock';
        if (!file_exists($lockFile)) {
            return false;
        }

        $mtime = @filemtime($lockFile);
        if ($mtime !== false && (time() - $mtime) > 10) {
            @unlink($lockFile);
            return false;
        }

        return true;
    }
}
