<?php

declare(strict_types=1);

namespace Fphp\Profiler;

/**
 * Real-time Performance & Cache Telemetry Tracker
 */
class StatsTracker
{
    protected string $statsFile;
    protected array $data = [];

    public function __construct(string $storageDir = '')
    {
        $dir = !empty($storageDir) ? $storageDir : dirname(__DIR__, 2) . '/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->statsFile = $dir . '/fphp_stats.json';
        $this->load();
    }

    protected function load(): void
    {
        if (file_exists($this->statsFile)) {
            $content = @file_get_contents($this->statsFile);
            $parsed = $content ? json_decode($content, true) : null;
            if (is_array($parsed)) {
                $this->data = $parsed;
                return;
            }
        }

        $this->data = [
            'hits' => 0,
            'misses' => 0,
            'bypasses' => 0,
            'total_requests' => 0,
            'saved_time_us' => 0.0,
            'recent_history' => [],
            'created_at' => time()
        ];
    }

    public function recordHit(string $key, float $timeUs, string $driver): void
    {
        $this->data['hits'] = ($this->data['hits'] ?? 0) + 1;
        $this->data['total_requests'] = ($this->data['total_requests'] ?? 0) + 1;

        // An estimated typical uncached request might take 15ms (15000us)
        $estimatedSaved = max(0, 15000.0 - $timeUs);
        $this->data['saved_time_us'] = ($this->data['saved_time_us'] ?? 0.0) + $estimatedSaved;

        $this->addHistory([
            'status' => 'HIT',
            'driver' => $driver,
            'key' => substr($key, 0, 40),
            'time_us' => $timeUs,
            'timestamp' => time()
        ]);

        $this->save();
    }

    public function recordMiss(string $key, float $timeUs): void
    {
        $this->data['misses'] = ($this->data['misses'] ?? 0) + 1;
        $this->data['total_requests'] = ($this->data['total_requests'] ?? 0) + 1;

        $this->addHistory([
            'status' => 'MISS',
            'driver' => 'generated',
            'key' => substr($key, 0, 40),
            'time_us' => $timeUs,
            'timestamp' => time()
        ]);

        $this->save();
    }

    public function recordBypass(string $reason): void
    {
        $this->data['bypasses'] = ($this->data['bypasses'] ?? 0) + 1;
        $this->data['total_requests'] = ($this->data['total_requests'] ?? 0) + 1;
        $this->save();
    }

    protected function addHistory(array $entry): void
    {
        if (!isset($this->data['recent_history']) || !is_array($this->data['recent_history'])) {
            $this->data['recent_history'] = [];
        }

        array_unshift($this->data['recent_history'], $entry);
        if (count($this->data['recent_history']) > 30) {
            array_pop($this->data['recent_history']);
        }
    }

    public function getSummary(): array
    {
        $hits = $this->data['hits'] ?? 0;
        $misses = $this->data['misses'] ?? 0;
        $total = $hits + $misses;
        $hitRatio = $total > 0 ? round(($hits / $total) * 100, 1) : 0.0;

        return [
            'hits' => $hits,
            'misses' => $misses,
            'bypasses' => $this->data['bypasses'] ?? 0,
            'total_requests' => $this->data['total_requests'] ?? 0,
            'hit_ratio' => $hitRatio,
            'saved_time_seconds' => round(($this->data['saved_time_us'] ?? 0) / 1000000, 2),
            'recent_history' => $this->data['recent_history'] ?? []
        ];
    }

    public function reset(): void
    {
        $this->data = [
            'hits' => 0,
            'misses' => 0,
            'bypasses' => 0,
            'total_requests' => 0,
            'saved_time_us' => 0.0,
            'recent_history' => [],
            'created_at' => time()
        ];
        $this->save();
    }

    protected function save(): void
    {
        // Periodic atomic write or low overhead write
        @file_put_contents($this->statsFile, json_encode($this->data, JSON_PRETTY_PRINT), LOCK_EX);
    }
}
