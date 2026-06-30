<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead;

use Rasuvaeff\Duration\Duration;

/**
 * Backing store that counts active concurrency slots across processes.
 *
 * @api
 */
interface BulkheadStore
{
    /**
     * Atomically take a slot if fewer than $maxConcurrent are currently active.
     *
     * @param non-empty-string $name
     * @param positive-int     $maxConcurrent
     * @param Duration         $lease TTL after which an unreleased slot is reclaimed
     *
     * @return non-empty-string|null Lease token when acquired, null when full
     */
    public function tryAcquire(string $name, int $maxConcurrent, Duration $lease): ?string;

    /**
     * Release a slot previously taken with $token. Idempotent.
     *
     * @param non-empty-string $name
     * @param non-empty-string $token
     */
    public function release(string $name, string $token): void;

    /**
     * Number of active (non-expired) slots for $name.
     *
     * @param non-empty-string $name
     *
     * @return int<0, max>
     */
    public function activeCount(string $name): int;
}
