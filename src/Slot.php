<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead;

/**
 * One held concurrency slot, as a value you can pass around: acquire it in
 * one place, release it in another.
 *
 * `release()` is idempotent — only the first call reaches the store. A slot
 * that is never released is reclaimed when its lease expires (Redis, APCu);
 * {@see InMemoryBulkheadStore} ignores leases, so there it stays taken.
 *
 * @api
 */
final class Slot
{
    private bool $released = false;

    /**
     * Obtained from {@see SharedBulkhead::acquire()} / {@see KeyedBulkhead::acquire()}
     * and their `tryAcquire()` twins; constructing one by hand is meant for
     * test doubles only.
     *
     * @param non-empty-string $name
     * @param non-empty-string $token
     */
    public function __construct(
        private readonly BulkheadStore $store,
        private readonly string $name,
        private readonly string $token,
    ) {}

    /**
     * @return non-empty-string
     */
    public function name(): string
    {
        return $this->name;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    /**
     * Return the slot to the store. Calling it again is a no-op — also
     * after a store failure: the slot then counts as released and its lease
     * reclaims it.
     */
    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        $this->store->release(name: $this->name, token: $this->token);
    }
}
