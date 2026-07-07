<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Tests\Support;

use Rasuvaeff\PropertyTesting\StateMachine\Command;

/**
 * Model-based command: release the slot held at $index (a no-op when fewer than
 * $index + 1 slots are held). The model is the number of slots currently held.
 */
final readonly class ReleaseCommand implements Command
{
    public function __construct(private int $index) {}

    #[\Override]
    public function preCondition(mixed $model): bool
    {
        return true;
    }

    #[\Override]
    public function nextState(mixed $model): mixed
    {
        \assert(is_int($model));

        return $this->index < $model ? $model - 1 : $model;
    }

    #[\Override]
    public function run(mixed $model, mixed $system): mixed
    {
        \assert($system instanceof BulkheadHarness);

        $system->release($this->index);

        return ['count' => $system->activeCount()];
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        \assert(is_int($model) && is_array($result));

        $expectedCount = $this->index < $model ? $model - 1 : $model;

        return $result['count'] === $expectedCount && $result['count'] >= 0;
    }

    #[\Override]
    public function __toString(): string
    {
        return 'Release(' . $this->index . ')';
    }
}
