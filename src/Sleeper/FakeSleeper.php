<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Sleeper;

use Rasuvaeff\Duration\Duration;

/**
 * Records requested sleeps instead of blocking. For tests.
 *
 * @api
 */
final class FakeSleeper implements SleeperInterface
{
    /** @var list<Duration> */
    private array $slept = [];

    #[\Override]
    public function sleep(Duration $duration): void
    {
        $this->slept[] = $duration;
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
