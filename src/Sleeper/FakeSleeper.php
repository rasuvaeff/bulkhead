<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Sleeper;

use Rasuvaeff\Duration\Duration;

/**
 * Records requested sleeps instead of blocking. For tests.
 *
 * Pass `$onSleep` to let a sleep move time elsewhere — typically a fake PSR-20
 * clock shared with the rest of the test, so "wait, then the state changed"
 * scenarios need no hand-written sleeper:
 *
 *     $sleeper = new FakeSleeper(onSleep: static fn(Duration $d) => $clock->advanceMs($d->toMillis()));
 *
 * @api
 */
final class FakeSleeper implements SleeperInterface
{
    /** @var list<Duration> */
    private array $slept = [];

    /**
     * @param (\Closure(Duration): void)|null $onSleep called after each sleep is recorded
     */
    public function __construct(
        private readonly ?\Closure $onSleep = null,
    ) {}

    #[\Override]
    public function sleep(Duration $duration): void
    {
        $this->slept[] = $duration;

        if ($this->onSleep instanceof \Closure) {
            ($this->onSleep)($duration);
        }
    }

    /**
     * @return list<Duration>
     */
    public function slept(): array
    {
        return $this->slept;
    }

    public function totalSlept(): Duration
    {
        $total = Duration::zero();
        foreach ($this->slept as $duration) {
            $total = $total->plus($duration);
        }

        return $total;
    }
}
