<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead;

use Rasuvaeff\Duration\Duration;

/**
 * Single-process {@see BulkheadStore} backed by an array.
 *
 * Useful for tests, CLI tools, and non-FPM single-process runtimes. The $lease
 * is ignored on purpose: a lease exists to reclaim slots from *other* processes
 * that died mid-call, which cannot happen within one process.
 *
 * Not a cross-process limiter — use {@see RedisBulkheadStore} to bound the whole
 * FPM worker pool.
 *
 * @api
 */
final class InMemoryBulkheadStore implements BulkheadStore
{
    /** @var array<string, array<non-empty-string, true>> */
    private array $slots = [];

    #[\Override]
    public function tryAcquire(string $name, int $maxConcurrent, Duration $lease): ?string
    {
        $active = $this->slots[$name] ?? [];
        if (count($active) >= $maxConcurrent) {
            return null;
        }

        $token = bin2hex(random_bytes(16));
        $active[$token] = true;
        $this->slots[$name] = $active;

        return $token;
    }

    #[\Override]
    public function release(string $name, string $token): void
    {
        unset($this->slots[$name][$token]);
    }

    #[\Override]
    public function activeCount(string $name): int
    {
        return count($this->slots[$name] ?? []);
    }
}
