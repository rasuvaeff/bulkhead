<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Sleeper;

use Rasuvaeff\Duration\Duration;

/**
 * @api
 */
final readonly class SystemSleeper implements SleeperInterface
{
    #[\Override]
    public function sleep(Duration $duration): void
    {
        usleep($duration->toMicros());
    }
}
