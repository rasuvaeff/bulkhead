<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Internal;

use Rasuvaeff\Bulkhead\Bulkhead;
use Rasuvaeff\Bulkhead\BulkheadStore;

/**
 * The view {@see \Rasuvaeff\Bulkhead\SharedBulkhead::withContext()} returns:
 * the same slots, waited for by a context-bound {@see SlotWaiter}.
 *
 * @internal
 */
final readonly class ContextBoundBulkhead implements Bulkhead
{
    /**
     * @param non-empty-string $name
     * @param positive-int $maxConcurrent
     */
    public function __construct(
        private SlotWaiter $waiter,
        private BulkheadStore $store,
        private string $name,
        private int $maxConcurrent,
    ) {}

    #[\Override]
    public function call(callable $callback): mixed
    {
        return $this->waiter->call(name: $this->name, maxConcurrent: $this->maxConcurrent, callback: $callback);
    }

    #[\Override]
    public function availableSlots(): int
    {
        return max(0, $this->maxConcurrent - $this->store->activeCount(name: $this->name));
    }
}
