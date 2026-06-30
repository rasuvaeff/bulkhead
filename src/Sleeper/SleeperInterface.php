<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Sleeper;

use Rasuvaeff\Duration\Duration;

/**
 * @api
 */
interface SleeperInterface
{
    public function sleep(Duration $duration): void;
}
