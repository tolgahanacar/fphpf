<?php

declare(strict_types=1);

namespace Fphp\Profiler;

/**
 * Nanosecond/Microsecond High-Resolution Performance Profiler
 */
class Metrics
{
    protected static array $timers = [];
    protected static array $memories = [];

    /**
     * Start a named high-resolution timer.
     *
     * @param string $name
     * @return int Start time in nanoseconds
     */
    public static function start(string $name = 'request'): int
    {
        $time = hrtime(true);
        self::$timers[$name] = $time;
        self::$memories[$name] = memory_get_usage();
        return $time;
    }

    /**
     * Stop timer and return elapsed time in microseconds (µs).
     *
     * @param string $name
     * @return float Elapsed microseconds
     */
    public static function stopMicroseconds(string $name = 'request'): float
    {
        if (!isset(self::$timers[$name])) {
            return 0.0;
        }

        $elapsedNano = hrtime(true) - self::$timers[$name];
        return round($elapsedNano / 1000, 2); // 1 µs = 1,000 ns
    }

    /**
     * Stop timer and return elapsed time in milliseconds (ms).
     *
     * @param string $name
     * @return float Elapsed milliseconds
     */
    public static function stopMilliseconds(string $name = 'request'): float
    {
        return round(self::stopMicroseconds($name) / 1000, 3);
    }

    /**
     * Return memory consumed since start.
     *
     * @param string $name
     * @return int Bytes
     */
    public static function memoryDelta(string $name = 'request'): int
    {
        if (!isset(self::$memories[$name])) {
            return 0;
        }

        return max(0, memory_get_usage() - self::$memories[$name]);
    }

    /**
     * Human readable format for microsecond or millisecond timings.
     *
     * @param float $microseconds
     * @return string
     */
    public static function formatTime(float $microseconds): string
    {
        if ($microseconds < 1000) {
            return round($microseconds, 1) . ' µs';
        }

        return round($microseconds / 1000, 2) . ' ms';
    }

    /**
     * Human readable format for byte sizes.
     *
     * @param int $bytes
     * @return string
     */
    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        return round($bytes / pow(1024, $power), 2) . ' ' . ($units[$power] ?? 'B');
    }
}
