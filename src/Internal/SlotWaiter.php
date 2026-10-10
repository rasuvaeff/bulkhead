<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Internal;

use Rasuvaeff\Bulkhead\BulkheadFullException;
use Rasuvaeff\Bulkhead\BulkheadStore;
use Rasuvaeff\Bulkhead\Sleeper\SleeperInterface;
use Rasuvaeff\Bulkhead\Sleeper\SystemSleeper;
use Rasuvaeff\Bulkhead\Slot;
use Rasuvaeff\Duration\Duration;

/**
 * The acquire-with-wait loop shared by {@see \Rasuvaeff\Bulkhead\SharedBulkhead}
 * and {@see \Rasuvaeff\Bulkhead\KeyedBulkhead}: both differ only in where the
 * name and the limit come from.
 *
 * @internal
 */
final readonly class SlotWaiter
{
    private Duration $pollInterval;
    private SleeperInterface $sleeper;

    /**
     * @param (\Closure(string, Duration): void)|null $onAccepted
     * @param (\Closure(string, Duration): void)|null $onRejected
     */
    public function __construct(
        private BulkheadStore $store,
        private Duration $lease,
        private Duration $maxWait,
        ?Duration $pollInterval,
        private float $pollJitter,
        ?SleeperInterface $sleeper,
        private ?\Closure $onAccepted,
        private ?\Closure $onRejected,
    ) {
        if ($lease->isZero()) {
            throw new \InvalidArgumentException('Lease must be greater than zero');
        }
        if ($pollJitter < 0.0 || $pollJitter > 1.0) {
            throw new \InvalidArgumentException('Poll jitter must be between 0 and 1');
        }

        $pollInterval ??= Duration::millis(50);
        if ($pollInterval->isZero()) {
            throw new \InvalidArgumentException('Poll interval must be greater than zero');
        }

        $this->pollInterval = $pollInterval;
        $this->sleeper = $sleeper ?? new SystemSleeper();
    }

    /**
     * @template T
     *
     * @param non-empty-string $name
     * @param positive-int     $maxConcurrent
     * @param callable(): T    $callback
     *
     * @throws BulkheadFullException
     *
     * @return T
     */
    public function call(string $name, int $maxConcurrent, callable $callback): mixed
    {
        $token = $this->acquireToken(name: $name, maxConcurrent: $maxConcurrent);

        try {
            return $callback();
        } finally {
            $this->store->release(name: $name, token: $token);
        }
    }

    /**
     * @param non-empty-string $name
     * @param positive-int     $maxConcurrent
     *
     * @throws BulkheadFullException
     */
    public function acquire(string $name, int $maxConcurrent): Slot
    {
        return $this->slot(name: $name, token: $this->acquireToken(name: $name, maxConcurrent: $maxConcurrent));
    }

    /**
     * One attempt, no waiting: null when every slot is taken.
     *
     * @param non-empty-string $name
     * @param positive-int     $maxConcurrent
     */
    public function tryAcquire(string $name, int $maxConcurrent): ?Slot
    {
        $token = $this->store->tryAcquire(name: $name, maxConcurrent: $maxConcurrent, lease: $this->lease);

        if ($token === null) {
            $this->rejected(name: $name, waited: Duration::zero());

            return null;
        }

        $this->accepted(name: $name, token: $token, waited: Duration::zero());

        return $this->slot(name: $name, token: $token);
    }

    /**
     * Waits for a slot up to `maxWait`, runs the observers, and hands back a
     * token the caller now owns.
     *
     * @param non-empty-string $name
     * @param positive-int     $maxConcurrent
     *
     * @throws BulkheadFullException
     *
     * @return non-empty-string
     */
    private function acquireToken(string $name, int $maxConcurrent): string
    {
        $waited = Duration::zero();

        while (true) {
            $token = $this->store->tryAcquire(name: $name, maxConcurrent: $maxConcurrent, lease: $this->lease);

            if ($token !== null) {
                $this->accepted(name: $name, token: $token, waited: $waited);

                return $token;
            }

            $remaining = $this->maxWait->minus($waited);
            if ($remaining->isZero()) {
                $this->rejected(name: $name, waited: $waited);

                throw new BulkheadFullException(
                    name: $name,
                    maxConcurrent: $maxConcurrent,
                    waited: $waited,
                    lease: $this->lease,
                );
            }

            $sleep = Duration::min($remaining, $this->jitteredPollInterval());
            $this->sleeper->sleep($sleep);
            $waited = $waited->plus($sleep);
        }
    }

    /**
     * A throwing `onAccepted` releases the slot before rethrowing: with
     * InMemoryBulkheadStore the lease is ignored, so a slot leaked here would
     * be gone for good.
     *
     * @param non-empty-string $name
     * @param non-empty-string $token
     */
    private function accepted(string $name, string $token, Duration $waited): void
    {
        if (!$this->onAccepted instanceof \Closure) {
            return;
        }

        try {
            ($this->onAccepted)($name, $waited);
        } catch (\Throwable $e) {
            $this->store->release(name: $name, token: $token);

            throw $e;
        }
    }

    private function rejected(string $name, Duration $waited): void
    {
        if ($this->onRejected instanceof \Closure) {
            ($this->onRejected)($name, $waited);
        }
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $token
     */
    private function slot(string $name, string $token): Slot
    {
        return new Slot(store: $this->store, name: $name, token: $token);
    }

    private function jitteredPollInterval(): Duration
    {
        if ($this->pollJitter === 0.0) {
            return $this->pollInterval;
        }

        $micros = $this->pollInterval->toMicros();
        $maxDelta = (int) ((float) $micros * $this->pollJitter);

        return Duration::micros(max(1, $micros + random_int(-$maxDelta, $maxDelta)));
    }
}
