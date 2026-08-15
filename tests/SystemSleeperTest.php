<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Tests;

use Rasuvaeff\Bulkhead\Sleeper\SystemSleeper;
use Rasuvaeff\Duration\Duration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SystemSleeper::class)]
final class SystemSleeperTest
{
    public function sleepsForAtLeastTheRequestedDuration(): void
    {
        $sleeper = new SystemSleeper();

        $start = hrtime(as_number: true);
        $sleeper->sleep(Duration::millis(20));
        $elapsedMicros = (hrtime(as_number: true) - $start) / 1000;

        Assert::true($elapsedMicros >= 10_000);
    }

    public function zeroDurationDoesNotSleepMeasurably(): void
    {
        $sleeper = new SystemSleeper();

        $start = hrtime(as_number: true);
        $sleeper->sleep(Duration::zero());
        $elapsedMicros = (hrtime(as_number: true) - $start) / 1000;

        Assert::true($elapsedMicros < 10_000);
    }
}
