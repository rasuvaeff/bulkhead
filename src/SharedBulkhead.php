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
    private const string NAME_PATTERN = '/^[A-Za-z0-9_.:-]+$/';

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
     * @param (\Closure(string): void)|null $onAccepted
     * @param (\Closure(string): void)|null $onRejected
     */
    public function __construct(
        string $name,
        int $maxConcurrent,
        private BulkheadStore $store,
        private Duration $lease,
        private Duration $maxWait,
        ?Duration $pollInterval = null,
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
        $token = $this->acquire();

        if ($token === null) {
            if ($this->onRejected instanceof \Closure) {
                ($this->onRejected)($this->name);
            }

            throw new BulkheadFullException(name: $this->name, maxConcurrent: $this->maxConcurrent);
        }

        if ($this->onAccepted instanceof \Closure) {
            ($this->onAccepted)($this->name);
        }

        try {
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
     * @return non-empty-string|null
     */
    private function acquire(): ?string
    {
        $waited = Duration::zero();

        while (true) {
            $token = $this->store->tryAcquire(
                name: $this->name,
                maxConcurrent: $this->maxConcurrent,
                lease: $this->lease,
            );

            if ($token !== null) {
                return $token;
            }

            $remaining = $this->maxWait->minus($waited);
            if ($remaining->isZero()) {
                return null;
            }

            $sleep = $remaining->isLessThan($this->pollInterval) ? $remaining : $this->pollInterval;
            $this->sleeper->sleep($sleep);
            $waited = $waited->plus($sleep);
        }
    }
}
