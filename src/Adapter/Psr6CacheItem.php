<?php

declare(strict_types=1);

namespace Fphp\Adapter;

use Psr\Cache\CacheItemInterface;

require_once __DIR__ . '/PsrShims.php';

class Psr6CacheItem implements CacheItemInterface
{
    protected string $key;
    protected mixed $value;
    protected bool $isHit;
    protected ?int $ttl = null;

    public function __construct(string $key, mixed $value, bool $isHit)
    {
        $this->key = $key;
        $this->value = $value;
        $this->isHit = $isHit;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->isHit ? $this->value : null;
    }

    public function isHit(): bool
    {
        return $this->isHit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;
        $this->isHit = true;
        return $this;
    }

    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        if ($expiration === null) {
            $this->ttl = 0;
        } else {
            $this->ttl = max(0, $expiration->getTimestamp() - time());
        }
        return $this;
    }

    public function expiresAfter(int|\DateInterval|null $time): static
    {
        if ($time === null) {
            $this->ttl = 0;
        } elseif ($time instanceof \DateInterval) {
            $now = new \DateTimeImmutable();
            $this->ttl = max(0, $now->add($time)->getTimestamp() - $now->getTimestamp());
        } else {
            $this->ttl = max(0, (int)$time);
        }
        return $this;
    }

    public function getTtl(): ?int
    {
        return $this->ttl;
    }

    public function getRawValue(): mixed
    {
        return $this->value;
    }
}
