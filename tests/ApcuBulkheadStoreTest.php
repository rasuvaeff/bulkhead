<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Tests;

use Rasuvaeff\Bulkhead\ApcuBulkheadStore;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ApcuBulkheadStore::class)]
final class ApcuBulkheadStoreTest
{
    public function isAvailableReflectsTheExtension(): void
    {
        Assert::same(ApcuBulkheadStore::isAvailable(), extension_loaded('apcu') && apcu_enabled());
    }
}
