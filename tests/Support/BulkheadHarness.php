<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Tests\Support;

use Rasuvaeff\Bulkhead\InMemoryBulkheadStore;
use Rasuvaeff\Duration\Duration;

/**
 * Stateful-test harness around an {@see InMemoryBulkheadStore}: it remembers the
 * tokens handed out by {@see tryAcquire()} so a release can target one by index.
 *
 * The system under test for the model-based property in
 * {@see \Rasuvaeff\Bulkhead\Tests\InMemoryBulkheadStoreTest}.
 */
final class BulkheadHarness
{
    private const string NAME = 'svc';

    private readonly InMemoryBulkheadStore $store;
    private readonly Duration $lease;

    /** @var list<string> */
    private array $tokens = [];

    public function __construct(private readonly int $maxConcurrent)
    {
        $this->store = new InMemoryBulkheadStore();
        // Long lease: InMemoryBulkheadStore ignores it, and nothing expires within
        // a single process, so the sequence is fully deterministic.
        $this->lease = Duration::seconds(60);
    }

    public function acquire(): bool
    {
        $token = $this->store->tryAcquire(self::NAME, $this->maxConcurrent, $this->lease);

        if ($token === null) {
            return false;
        }

        $this->tokens[] = $token;

        return true;
    }

    public function release(int $index): void
    {
        if ($index < 0 || $index >= count($this->tokens)) {
            return;
        }

        $this->store->release(self::NAME, $this->tokens[$index]);
        array_splice($this->tokens, $index, 1);
    }

    public function activeCount(): int
    {
        return $this->store->activeCount(self::NAME);
    }
}
