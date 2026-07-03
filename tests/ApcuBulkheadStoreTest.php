<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Tests;

use Rasuvaeff\Bulkhead\ApcuBulkheadStore;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ApcuBulkheadStore::class)]
final class ApcuBulkheadStoreTest
{
    public function isAvailableReflectsTheExtension(): void
    {
        Assert::same(ApcuBulkheadStore::isAvailable(), extension_loaded('apcu') && apcu_enabled());
    }

    public function acceptsMinimalLockTuning(): void
    {
        Assert::instanceOf(new ApcuBulkheadStore(lockMaxAttempts: 1, lockRetryMicros: 1), ApcuBulkheadStore::class);
    }

    public function rejectsNonPositiveLockMaxAttempts(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Lock max attempts must be greater than or equal to 1');

        new ApcuBulkheadStore(lockMaxAttempts: 0);
    }

    public function rejectsNonPositiveLockRetryMicros(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Lock retry micros must be greater than or equal to 1');

        new ApcuBulkheadStore(lockRetryMicros: 0);
    }
}
