<?php

declare(strict_types=1);

namespace Fphp\Warmer;

/**
 * Predictive Markov Pre-Caching Engine
 * 
 * Analyzes real-time user browsing paths and calculates transition probabilities:
 * P(Target Page B | Current Page A)
 * 
 * Automatically predicts the user's next action and pre-warms target pages in RAM
 * before the user even clicks, achieving a theoretical 100% Cache HIT rate.
 */
class MarkovWarmer
{
    protected string $storageFile;
    protected array $matrix = [];
    protected bool $isDirty = false;
    protected static float $lastSaveTime = 0.0;

    public function __construct(string $storageDir = '')
    {
        $dir = !empty($storageDir) ? $storageDir : dirname(__DIR__, 2) . '/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->storageFile = $dir . '/markov_transitions.json';
        $this->load();
    }

    protected function load(): void
    {
        if (file_exists($this->storageFile)) {
            $data = @file_get_contents($this->storageFile);
            $parsed = $data ? json_decode($data, true) : null;
            if (is_array($parsed)) {
                $this->matrix = $parsed;
            }
        }
    }

    /**
     * Record a transition from Page A to Page B.
     *
     * @param string $fromUrl
     * @param string $toUrl
     */
    public function recordTransition(string $fromUrl, string $toUrl): void
    {
        $from = $this->normalizePath($fromUrl);
        $to = $this->normalizePath($toUrl);

        if ($from === $to || empty($from) || empty($to)) {
            return;
        }

        if (!isset($this->matrix[$from])) {
            $this->matrix[$from] = [];
        }

        $this->matrix[$from][$to] = ($this->matrix[$from][$to] ?? 0) + 1;
        $this->isDirty = true;

        // Throttled persistence (save at most once every 3 seconds)
        if ((microtime(true) - self::$lastSaveTime) > 3.0) {
            $this->save();
        }
    }

    /**
     * Predict likely next pages given current page URL.
     *
     * @param string $currentUrl
     * @param float $minProbability Minimum probability threshold (0.0 to 1.0)
     * @return array<string, float> Array of [targetUrl => probability]
     */
    public function predictNext(string $currentUrl, float $minProbability = 0.20): array
    {
        $from = $this->normalizePath($currentUrl);
        if (!isset($this->matrix[$from]) || empty($this->matrix[$from])) {
            return [];
        }

        $targets = $this->matrix[$from];
        $totalTransitions = array_sum($targets);
        if ($totalTransitions <= 0) {
            return [];
        }

        $predictions = [];
        foreach ($targets as $target => $count) {
            $prob = round($count / $totalTransitions, 3);
            if ($prob >= $minProbability) {
                $predictions[$target] = $prob;
            }
        }

        arsort($predictions);
        return $predictions;
    }

    /**
     * Automatically trigger parallel pre-warming for predicted target URLs.
     *
     * @param string $currentUrl
     * @param CacheWarmer $warmer
     * @param float $threshold
     * @return array Pre-warmed URLs
     */
    public function autoWarmPredicted(string $currentUrl, CacheWarmer $warmer, float $threshold = 0.20): array
    {
        $predictions = $this->predictNext($currentUrl, $threshold);
        if (empty($predictions)) {
            return [];
        }

        $urlsToWarm = array_keys($predictions);
        $warmer->warmUrls($urlsToWarm);

        return $urlsToWarm;
    }

    /**
     * Normalize URL to path to aggregate transitions accurately.
     */
    protected function normalizePath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path === null || $path === '') {
            $path = $url;
        }
        return rtrim($path, '/') ?: '/';
    }

    public function save(bool $force = false): void
    {
        if (!$this->isDirty && !$force) {
            return;
        }

        self::$lastSaveTime = microtime(true);
        @file_put_contents($this->storageFile, json_encode($this->matrix, JSON_PRETTY_PRINT), LOCK_EX);
        $this->isDirty = false;
    }

    public function getMatrix(): array
    {
        return $this->matrix;
    }

    public function clear(): void
    {
        $this->matrix = [];
        $this->isDirty = true;
        $this->save(true);
    }

    public function __destruct()
    {
        if ($this->isDirty) {
            $this->save(true);
        }
    }
}
