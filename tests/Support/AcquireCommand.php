<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Tests\Support;

use Rasuvaeff\PropertyTesting\StateMachine\Command;

/**
 * Model-based command: take a concurrency slot. Always applicable; grants iff the
 * model is below the limit. The model is the number of slots currently held.
 */
final readonly class AcquireCommand implements Command
{
    public function __construct(private int $maxConcurrent) {}

    #[\Override]
    public function preCondition(mixed $model): bool
    {
        return true;
    }

    #[\Override]
    public function nextState(mixed $model): mixed
    {
        \assert(is_int($model));

        return $model < $this->maxConcurrent ? $model + 1 : $model;
    }

    #[\Override]
    public function run(mixed $model, mixed $system): mixed
    {
        \assert($system instanceof BulkheadHarness);

        $granted = $system->acquire();

        return ['granted' => $granted, 'count' => $system->activeCount()];
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        \assert(is_int($model) && is_array($result));

        $expectedGranted = $model < $this->maxConcurrent;
        $expectedCount = $expectedGranted ? $model + 1 : $model;

        return $result['granted'] === $expectedGranted
            && $result['count'] === $expectedCount
            && $result['count'] >= 0
            && $result['count'] <= $this->maxConcurrent;
    }

    #[\Override]
    public function __toString(): string
    {
        return 'Acquire';
    }
}
