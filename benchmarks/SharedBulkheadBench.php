<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Benchmarks;

use Rasuvaeff\Bulkhead\InMemoryBulkheadStore;
use Rasuvaeff\Bulkhead\SharedBulkhead;
use Rasuvaeff\Duration\Duration;
use Testo\Bench;

final class SharedBulkheadBench
{
    private static ?SharedBulkhead $bulkhead = null;

    #[Bench(
        callables: [
            'plain' => [self::class, 'plainCall'],
        ],
        calls: 100_000,
        iterations: 10,
    )]
    public static function guardedCall(): int
    {
        return self::bulkhead()->call(static fn(): int => 42);
    }

    public static function plainCall(): int
    {
        return 42;
    }

    private static function bulkhead(): SharedBulkhead
    {
        if (self::$bulkhead === null) {
            self::$bulkhead = new SharedBulkhead(
                name: 'bench',
                maxConcurrent: 1_000_000,
                store: new InMemoryBulkheadStore(),
                lease: Duration::seconds(60),
                maxWait: Duration::zero(),
            );
        }

        return self::$bulkhead;
    }
}
