<?php

declare(strict_types=1);

namespace Fphp\Fragment;

use Fphp\Driver\CacheDriverInterface;

/**
 * High-Speed Fragment & Component Microcaching Engine
 * 
 * Allows caching specific heavy HTML blocks (widgets, sidebars, navigation)
 * even on dynamic pages where Full-Page Cache must be bypassed (e.g., logged-in users, carts).
 */
class FragmentCache
{
    protected CacheDriverInterface $driver;
    protected array $activeFragments = [];

    public function __construct(CacheDriverInterface $driver)
    {
        $this->driver = $driver;
    }

    /**
     * Start fragment caching buffer.
     * 
     * Usage:
     *   if (FphpCache::startFragment('sidebar_widget', 3600)):
     *       // Heavy rendering / DB queries
     *       FphpCache::endFragment();
     *   endif;
     * 
     * @param string $id Unique fragment identifier
     * @param int $ttl Lifetime in seconds
     * @return bool True if cache MISS (render contents), False if cache HIT (already outputted)
     */
    public function start(string $id, int $ttl = 3600): bool
    {
        $cacheKey = 'fragment:' . $id;
        $cached = $this->driver->get($cacheKey);

        if ($cached !== null && is_string($cached)) {
            echo $cached;
            return false;
        }

        // Cache MISS: Start output buffering
        $this->activeFragments[] = ['id' => $id, 'key' => $cacheKey, 'ttl' => $ttl];
        ob_start();
        return true;
    }

    /**
     * End output buffering, store captured fragment, and output to page.
     *
     * @return string The captured HTML content
     */
    public function end(): string
    {
        if (empty($this->activeFragments)) {
            return '';
        }

        $fragment = array_pop($this->activeFragments);
        $content = ob_get_clean() ?: '';

        if (!empty($content)) {
            $this->driver->set($fragment['key'], $content, (int)$fragment['ttl']);
        }

        echo $content;
        return $content;
    }

    /**
     * Convenient closure-based fragment execution.
     *
     * @param string $id
     * @param int $ttl
     * @param callable $callback
     * @return string
     */
    public function remember(string $id, int $ttl, callable $callback): string
    {
        $cacheKey = 'fragment:' . $id;
        $cached = $this->driver->get($cacheKey);

        if ($cached !== null && is_string($cached)) {
            return $cached;
        }

        $content = (string)$callback();
        $this->driver->set($cacheKey, $content, $ttl);
        return $content;
    }
}
