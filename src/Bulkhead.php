<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead;

/**
 * @api
 */
interface Bulkhead
{
    /**
     * Run $callback while holding one of the limited concurrency slots.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @throws BulkheadFullException when no slot is available within the wait budget
     *
     * @return T
     */
    public function call(callable $callback): mixed;

    /**
     * Best-effort number of slots currently free (never negative).
     */
    public function availableSlots(): int;
}
