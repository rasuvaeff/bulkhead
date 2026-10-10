<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Tests;

use Rasuvaeff\Bulkhead\BulkheadStore;
use Rasuvaeff\Bulkhead\InMemoryBulkheadStore;
use Rasuvaeff\Bulkhead\Slot;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

use function Rasuvaeff\Understudy\expect;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(Slot::class)]
final class SlotTest
{
    public function releaseReturnsTheSlotToTheStore(): void
    {
        $store = new InMemoryBulkheadStore();
        $token = $store->tryAcquire('svc', 1, Duration::seconds(5));
        Assert::true($token !== null);
        $slot = new Slot(store: $store, name: 'svc', token: (string) $token);

        Assert::false($slot->isReleased());

        $slot->release();

        Assert::true($slot->isReleased());
        Assert::same($store->activeCount('svc'), 0);
        Assert::same($slot->name(), 'svc');
    }

    public function secondReleaseNeverReachesTheStore(): void
    {
        $store = Understudy::for(BulkheadStore::class);
        expect(fn() => $store->release('svc', 'token-1'));
        $slot = new Slot(store: $store, name: 'svc', token: 'token-1');

        $slot->release();
        $slot->release();

        Assert::true($slot->isReleased());
    }

    public function failedReleaseStillCountsAsReleased(): void
    {
        $store = Understudy::for(BulkheadStore::class);
        when(fn() => $store->release(Arg::any(), Arg::any()))->throws(new \RuntimeException('store gone'));
        $slot = new Slot(store: $store, name: 'svc', token: 'token-1');

        try {
            $slot->release();
            Assert::true(actual: false);
        } catch (\RuntimeException $e) {
            Assert::same($e->getMessage(), 'store gone');
        }

        Assert::true($slot->isReleased());
        $slot->release();
    }
}
