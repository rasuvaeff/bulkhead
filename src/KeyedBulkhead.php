<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead;

use Rasuvaeff\Bulkhead\Internal\Limits;
use Rasuvaeff\Bulkhead\Internal\SlotWaiter;
use Rasuvaeff\Bulkhead\Sleeper\SleeperInterface;
use Rasuvaeff\Duration\Duration;

/**
 * A family of bulkheads over one store whose name and limit are chosen per
 * call — one limit per upstream proxy, per tenant, per browser context —
 * with the same wait, lease and observer semantics as {@see SharedBulkhead}.
 *
 * Each name is an independent bulkhead. Pass the same limit for a name on
 * every call: the store compares the active count against whatever limit
 * the current call brings, so callers disagreeing on the limit of one name
 * effectively get the larger of the two.
 *
 * @api
 */
final readonly class KeyedBulkhead
{
    private SlotWaiter $waiter;

    /**
     * Same parameters as {@see SharedBulkhead}, minus the name and the limit.
     *
     * @param (\Closure(string, Duration): void)|null $onAccepted receives the name
     *                            and how long the call waited for its slot
     * @param (\Closure(string, Duration): void)|null $onRejected receives the name
     *                            and how long the call waited before giving up
     */
    public function __construct(
        private BulkheadStore $store,
        Duration $lease,
        Duration $maxWait,
        ?Duration $pollInterval = null,
        float $pollJitter = 0.0,
        ?SleeperInterface $sleeper = null,
        ?\Closure $onAccepted = null,
        ?\Closure $onRejected = null,
    ) {
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

    /**
     * Run $callback while holding one of $maxConcurrent slots of $name.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @throws BulkheadFullException     when no slot is available within `maxWait`
     * @throws \InvalidArgumentException on an invalid name or a limit below 1
     *
     * @return T
     */
    public function call(string $name, int $maxConcurrent, callable $callback): mixed
    {
        return $this->waiter->call(
            name: Limits::name($name),
            maxConcurrent: Limits::maxConcurrent($maxConcurrent),
            callback: $callback,
        );
    }

    /**
     * Take a slot of $name and keep it past this call: wait up to `maxWait`,
     * then hand it over. Release it with {@see Slot::release()} wherever the
     * work actually ends.
     *
     * @throws BulkheadFullException     when no slot is available within `maxWait`
     * @throws \InvalidArgumentException on an invalid name or a limit below 1
     */
    public function acquire(string $name, int $maxConcurrent): Slot
    {
        return $this->waiter->acquire(name: Limits::name($name), maxConcurrent: Limits::maxConcurrent($maxConcurrent));
    }

    /**
     * {@see acquire()} without waiting and without throwing: null when every
     * slot of $name is taken (`onRejected` still fires).
     *
     * @throws \InvalidArgumentException on an invalid name or a limit below 1
     */
    public function tryAcquire(string $name, int $maxConcurrent): ?Slot
    {
        return $this->waiter->tryAcquire(name: Limits::name($name), maxConcurrent: Limits::maxConcurrent($maxConcurrent));
    }

    /**
     * Best-effort number of free slots of $name under $maxConcurrent (never negative).
     *
     * @throws \InvalidArgumentException on an invalid name or a limit below 1
     */
    public function availableSlots(string $name, int $maxConcurrent): int
    {
        $name = Limits::name($name);

        return max(0, Limits::maxConcurrent($maxConcurrent) - $this->store->activeCount(name: $name));
    }
}
