<?php

declare(strict_types=1);

namespace Fphp\Tags;

use Fphp\Driver\CacheDriverInterface;

/**
 * Microsecond Cache Tagging via Version Clocks
 * 
 * Uses O(1) tag versioning instead of scanning or indexing keys.
 * Invalidating a tag is an instantaneous single integer increment!
 */
class TagManager
{
    protected CacheDriverInterface $driver;
    protected string $tagPrefix = 'fphp_tag_ver:';

    public function __construct(CacheDriverInterface $driver)
    {
        $this->driver = $driver;
    }

    /**
     * Get the current version numbers for an array of tags.
     *
     * @param array $tags
     * @return array<string, int>
     */
    public function getTagVersions(array $tags): array
    {
        $versions = [];
        foreach ($tags as $tag) {
            $tag = trim((string)$tag);
            if (empty($tag)) {
                continue;
            }

            $key = $this->tagPrefix . $tag;
            $ver = $this->driver->get($key);

            if ($ver === null || !is_numeric($ver)) {
                $ver = time();
                $this->driver->set($key, $ver, 0); // tags don't expire
            }

            $versions[$tag] = (int)$ver;
        }

        return $versions;
    }

    /**
     * Validate whether an item's recorded tag versions still match current versions.
     *
     * @param array<string, int> $itemTagVersions
     * @return bool True if valid, false if any tag was invalidated
     */
    public function areVersionsValid(array $itemTagVersions): bool
    {
        if (empty($itemTagVersions)) {
            return true;
        }

        $currentVersions = $this->getTagVersions(array_keys($itemTagVersions));

        foreach ($itemTagVersions as $tag => $ver) {
            if (!isset($currentVersions[$tag]) || $currentVersions[$tag] !== $ver) {
                return false;
            }
        }

        return true;
    }

    /**
     * Invalidate one or more tags in O(1) time.
     *
     * @param array|string $tags
     * @return void
     */
    public function invalidate(array|string $tags): void
    {
        $tags = (array)$tags;
        foreach ($tags as $tag) {
            $tag = trim((string)$tag);
            if (!empty($tag)) {
                // Changing the version instantly invalidates all items referencing this tag
                $newVersion = (int)(microtime(true) * 10000);
                $this->driver->set($this->tagPrefix . $tag, $newVersion, 0);
            }
        }
    }
}
