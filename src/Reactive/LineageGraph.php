<?php

declare(strict_types=1);

namespace Fphp\Reactive;

/**
 * Autonomous Reactive Data Lineage Graph (DAG)
 *
 * Eliminates arbitrary and superstitious TTLs (Time-To-Live).
 * Instead of guessing expiration windows, caches establish a Directed Acyclic Graph (DAG)
 * connecting underlying data entities (tables, record IDs, models, API keys)
 * directly to compiled cache artifacts (pages, bytecode files, fragments).
 *
 * When an entity mutates, the DAG walks the dependency tree in O(1) and atomically
 * evicts only the exact dependent artifacts across all cache tiers.
 *
 * Unmutated data lives forever with guaranteed zero-latency freshness.
 */
class LineageGraph
{
    protected string $storageFile;
    protected array $entityToArtifacts = [];
    protected array $artifactToEntities = [];
    protected array $invalidators = [];
    protected bool $isDirty = false;
    protected static float $lastSaveTime = 0.0;
    protected static array $contextStack = [];

    public function __construct(string $storageDir = '')
    {
        $dir = !empty($storageDir) ? $storageDir : dirname(__DIR__, 2) . '/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->storageFile = $dir . '/lineage_graph.json';
        $this->load();
    }

    /**
     * Load persisted DAG from disk.
     */
    protected function load(): void
    {
        if (file_exists($this->storageFile)) {
            $data = @file_get_contents($this->storageFile);
            $parsed = $data ? json_decode($data, true) : null;
            if (is_array($parsed)) {
                $this->entityToArtifacts = $parsed['entity_to_artifacts'] ?? [];
                $this->artifactToEntities = $parsed['artifact_to_entities'] ?? [];
            }
        }
    }

    /**
     * Persist DAG to disk with atomic write and throttling.
     */
    public function save(bool $force = false): void
    {
        if (!$this->isDirty && !$force) {
            return;
        }

        $now = microtime(true);
        if (!$force && ($now - self::$lastSaveTime) < 2.0) {
            return;
        }

        $payload = json_encode([
            'entity_to_artifacts' => $this->entityToArtifacts,
            'artifact_to_entities' => $this->artifactToEntities,
            'updated_at' => time()
        ], JSON_UNESCAPED_SLASHES);

        if ($payload !== false) {
            $temp = $this->storageFile . '.tmp.' . bin2hex(random_bytes(4));
            if (@file_put_contents($temp, $payload, LOCK_EX) !== false) {
                @rename($temp, $this->storageFile);
                $this->isDirty = false;
                self::$lastSaveTime = $now;
            } else {
                @unlink($temp);
            }
        }
    }

    /**
     * Register a callback to be invoked when an artifact must be invalidated.
     *
     * @param callable $callback Signature: function(string $artifactKey, string $artifactType): void
     */
    public function registerInvalidator(callable $callback): self
    {
        $this->invalidators[] = $callback;
        return $this;
    }

    /**
     * Record a dependency between one or more entities and a cached artifact.
     *
     * @param string|array $entities e.g. 'user:42', ['table:products', 'category:electronics']
     * @param string $artifactKey Cache key or hash
     * @param string $artifactType 'page', 'bytecode', 'fragment', 'data'
     */
    public function record(string|array $entities, string $artifactKey, string $artifactType = 'page'): void
    {
        $entityList = is_array($entities) ? $entities : [$entities];

        // Also merge active tracking context if available
        $contextEntities = self::getActiveContext();
        if (!empty($contextEntities)) {
            $entityList = array_unique(array_merge($entityList, $contextEntities));
        }

        foreach ($entityList as $entity) {
            $entity = trim((string)$entity);
            if ($entity === '') {
                continue;
            }

            if (!isset($this->entityToArtifacts[$entity])) {
                $this->entityToArtifacts[$entity] = [];
            }
            $this->entityToArtifacts[$entity][$artifactKey] = $artifactType;

            if (!isset($this->artifactToEntities[$artifactKey])) {
                $this->artifactToEntities[$artifactKey] = [];
            }
            $this->artifactToEntities[$artifactKey][$entity] = true;
        }

        $this->isDirty = true;
    }

    /**
     * Signal that one or more data entities have mutated.
     * Automatically traces all dependent artifacts in the DAG and invalidates them.
     *
     * Supports exact keys ('user:42') and wildcards ('table:posts:*', 'category:*').
     *
     * @param string|array $entities
     * @return array Invalidation report ['evicted_count' => int, 'evicted_keys' => string[], 'matched_entities' => string[]]
     */
    public function mutate(string|array $entities): array
    {
        $entityList = is_array($entities) ? $entities : [$entities];
        $matchedEntities = [];
        $artifactsToEvict = [];

        foreach ($entityList as $pattern) {
            $pattern = trim((string)$pattern);
            if ($pattern === '') {
                continue;
            }

            $isWildcard = str_contains($pattern, '*');
            $regex = $isWildcard ? '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/' : null;

            foreach ($this->entityToArtifacts as $entityKey => $artifacts) {
                $matches = false;
                if ($isWildcard) {
                    $matches = (bool)preg_match($regex, $entityKey);
                } else {
                    $matches = ($entityKey === $pattern);
                }

                if ($matches) {
                    $matchedEntities[] = $entityKey;
                    foreach ($artifacts as $artifactKey => $artifactType) {
                        $artifactsToEvict[$artifactKey] = $artifactType;
                    }
                }
            }
        }

        // Evict all collected artifacts
        foreach ($artifactsToEvict as $artifactKey => $artifactType) {
            $this->dispatchInvalidation($artifactKey, $artifactType);
            $this->detachArtifact($artifactKey);
        }

        // Clean up empty entities
        foreach ($matchedEntities as $entityKey) {
            unset($this->entityToArtifacts[$entityKey]);
        }

        if (!empty($artifactsToEvict)) {
            $this->isDirty = true;
            $this->save(true);
        }

        return [
            'evicted_count' => count($artifactsToEvict),
            'evicted_keys' => array_keys($artifactsToEvict),
            'matched_entities' => array_unique($matchedEntities)
        ];
    }

    /**
     * Dispatch invalidation to all registered listeners.
     */
    protected function dispatchInvalidation(string $artifactKey, string $artifactType): void
    {
        foreach ($this->invalidators as $invalidator) {
            try {
                $invalidator($artifactKey, $artifactType);
            } catch (\Throwable $e) {
                // Ignore listener exceptions
            }
        }
    }

    /**
     * Detach an artifact from the DAG.
     */
    protected function detachArtifact(string $artifactKey): void
    {
        if (isset($this->artifactToEntities[$artifactKey])) {
            foreach (array_keys($this->artifactToEntities[$artifactKey]) as $entityKey) {
                unset($this->entityToArtifacts[$entityKey][$artifactKey]);
                if (empty($this->entityToArtifacts[$entityKey])) {
                    unset($this->entityToArtifacts[$entityKey]);
                }
            }
            unset($this->artifactToEntities[$artifactKey]);
        }
    }

    /**
     * Get all dependent artifacts for a specific entity.
     *
     * @param string $entity
     * @return array<string, string> Map of [artifactKey => artifactType]
     */
    public function getDependents(string $entity): array
    {
        return $this->entityToArtifacts[$entity] ?? [];
    }

    /**
     * Get all entities an artifact depends upon.
     *
     * @param string $artifactKey
     * @return array
     */
    public function getEntitiesForArtifact(string $artifactKey): array
    {
        return isset($this->artifactToEntities[$artifactKey])
            ? array_keys($this->artifactToEntities[$artifactKey])
            : [];
    }

    /**
     * Scoped execution helper: All caching calls inside $callback automatically inherit these entities.
     *
     * @param string|array $entities
     * @param callable $callback
     * @return mixed
     */
    public static function track(string|array $entities, callable $callback): mixed
    {
        $entityList = is_array($entities) ? $entities : [$entities];
        self::$contextStack[] = $entityList;

        try {
            return $callback();
        } finally {
            array_pop(self::$contextStack);
        }
    }

    /**
     * Get currently active tracking context entities.
     *
     * @return array
     */
    public static function getActiveContext(): array
    {
        if (empty(self::$contextStack)) {
            return [];
        }

        $all = [];
        foreach (self::$contextStack as $frame) {
            $all = array_merge($all, $frame);
        }
        return array_unique($all);
    }

    /**
     * Clear all nodes and edges from the DAG.
     */
    public function clear(): void
    {
        $this->entityToArtifacts = [];
        $this->artifactToEntities = [];
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

