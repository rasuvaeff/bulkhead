<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead;

use Rasuvaeff\Bulkhead\Internal\Limits;
use Rasuvaeff\Bulkhead\Internal\SlotWaiter;
use Rasuvaeff\Bulkhead\Sleeper\SleeperInterface;
use Rasuvaeff\Duration\Duration;

/**
 * Concurrency limiter that admits at most $maxConcurrent simultaneous calls,
 * counted across every process sharing the {@see BulkheadStore}.
 *
 * The name and the limit are fixed per instance; when they are known only
 * per call (one limit per proxy, per tenant), use {@see KeyedBulkhead}.
 *
 * @api
 */
final readonly class SharedBulkhead implements Bulkhead
{
    /** @var non-empty-string */
    private string $name;
    /** @var positive-int */
    private int $maxConcurrent;
    private SlotWaiter $waiter;

    /**
     * @param Duration  $lease    TTL of a held slot; MUST exceed the longest expected
     *                            callback runtime, or the slot is reclaimed mid-call and
     *                            concurrency can exceed $maxConcurrent
     * @param Duration  $maxWait  how long to wait for a slot before failing; zero = fast-fail
     * @param ?Duration $pollInterval polling granularity while waiting (default 50ms)
     * @param float     $pollJitter randomizes each poll sleep within ±(pollJitter × pollInterval),
     *                            0.0..1.0; desynchronizes waiters so a freed slot is not
     *                            stampeded by every worker at once
     * @param (\Closure(string, Duration): void)|null $onAccepted receives the bulkhead
     *                            name and how long the call waited for its slot
     * @param (\Closure(string, Duration): void)|null $onRejected receives the bulkhead
     *                            name and how long the call waited before giving up
     */
    public function __construct(
        string $name,
        int $maxConcurrent,
        private BulkheadStore $store,
        Duration $lease,
        Duration $maxWait,
        ?Duration $pollInterval = null,
        float $pollJitter = 0.0,
        ?SleeperInterface $sleeper = null,
        ?\Closure $onAccepted = null,
        ?\Closure $onRejected = null,
    ) {
        $this->name = Limits::name($name);
        $this->maxConcurrent = Limits::maxConcurrent($maxConcurrent);
        $this->waiter = new SlotWaiter(
            store: $store,
            lease: $lease,
            maxWait: $maxWait,
            pollInterval: $pollInterval,
            pollJitter: $pollJitter,
            sleeper: $sleeper,
            onAccepted: $onAccepted,
            onRejected: $onRejected,
        );
    }

    #[\Override]
    public function call(callable $callback): mixed
    {
        return $this->waiter->call(name: $this->name, maxConcurrent: $this->maxConcurrent, callback: $callback);
    }

    /**
     * Take a slot and keep it past this call: wait up to `maxWait`, then hand
     * the slot over. Release it with {@see Slot::release()} wherever the work
     * actually ends.
     *
     * @throws BulkheadFullException when no slot is available within `maxWait`
     */
    public function acquire(): Slot
    {
        return $this->waiter->acquire(name: $this->name, maxConcurrent: $this->maxConcurrent);
    }

    /**
     * {@see acquire()} without waiting and without throwing: null when every
     * slot is taken (`onRejected` still fires).
     */
    public function tryAcquire(): ?Slot
    {
        return $this->waiter->tryAcquire(name: $this->name, maxConcurrent: $this->maxConcurrent);
    }

    #[\Override]
    public function availableSlots(): int
    {
        return max(0, $this->maxConcurrent - $this->store->activeCount(name: $this->name));
    }

    /**
     * @return non-empty-string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return positive-int
     */
    public function maxConcurrent(): int
    {
        return $this->maxConcurrent;
    }
}
