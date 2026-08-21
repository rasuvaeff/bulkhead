<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead;

use Rasuvaeff\Bulkhead\Sleeper\SleeperInterface;
use Rasuvaeff\Bulkhead\Sleeper\SystemSleeper;
use Rasuvaeff\Duration\Duration;

/**
 * Concurrency limiter that admits at most $maxConcurrent simultaneous calls,
 * counted across every process sharing the {@see BulkheadStore}.
 *
 * @api
 */
final readonly class SharedBulkhead implements Bulkhead
{
    private const string NAME_PATTERN = '/^[A-Za-z0-9_.:-]+\z/';

    /** @var non-empty-string */
    private string $name;
    /** @var positive-int */
    private int $maxConcurrent;
    private Duration $pollInterval;
    private SleeperInterface $sleeper;

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
        private Duration $lease,
        private Duration $maxWait,
        ?Duration $pollInterval = null,
        private float $pollJitter = 0.0,
        ?SleeperInterface $sleeper = null,
        private ?\Closure $onAccepted = null,
        private ?\Closure $onRejected = null,
    ) {
        if ($name === '' || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid bulkhead name "%s"', $name));
        }
        if ($maxConcurrent < 1) {
            throw new \InvalidArgumentException('Max concurrent must be greater than or equal to 1');
        }
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

        $this->name = $name;
        $this->maxConcurrent = $maxConcurrent;
        $this->pollInterval = $pollInterval;
        $this->sleeper = $sleeper ?? new SystemSleeper();
    }

    #[\Override]
    public function call(callable $callback): mixed
    {
        [$token, $waited] = $this->acquire();

        if ($token === null) {
            if ($this->onRejected instanceof \Closure) {
                ($this->onRejected)($this->name, $waited);
            }

            throw new BulkheadFullException(name: $this->name, maxConcurrent: $this->maxConcurrent);
        }

        try {
            // Inside the try: a throwing observer callback must not leak the
            // just-acquired slot past release() (with InMemoryBulkheadStore
            // the lease is ignored, so the leak would be permanent).
            if ($this->onAccepted instanceof \Closure) {
                ($this->onAccepted)($this->name, $waited);
            }

            return $callback();
        } finally {
            $this->store->release(name: $this->name, token: $token);
        }
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

    /**
     * @return array{non-empty-string|null, Duration} acquired token (null when full)
     *                                                and total time spent waiting
     */
    private function acquire(): array
    {
        $waited = Duration::zero();

        while (true) {
            $token = $this->store->tryAcquire(
                name: $this->name,
                maxConcurrent: $this->maxConcurrent,
                lease: $this->lease,
            );

            if ($token !== null) {
                return [$token, $waited];
            }

            $remaining = $this->maxWait->minus($waited);
            if ($remaining->isZero()) {
                return [null, $waited];
            }

            $sleep = Duration::min($remaining, $this->jitteredPollInterval());
            $this->sleeper->sleep($sleep);
            $waited = $waited->plus($sleep);
        }
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
