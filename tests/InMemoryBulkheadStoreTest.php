<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Tests;

use Rasuvaeff\Bulkhead\InMemoryBulkheadStore;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(InMemoryBulkheadStore::class)]
final class InMemoryBulkheadStoreTest
{
    private InMemoryBulkheadStore $store;
    private Duration $lease;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->store = new InMemoryBulkheadStore();
        $this->lease = Duration::seconds(5);
    }

    public function acquiresUpToMaxThenReturnsNull(): void
    {
        $first = $this->store->tryAcquire('svc', 2, $this->lease);
        $second = $this->store->tryAcquire('svc', 2, $this->lease);
        $third = $this->store->tryAcquire('svc', 2, $this->lease);

        Assert::true($first !== null);
        Assert::true($second !== null);
        Assert::null($third);
    }

    public function activeCountTracksAcquisitions(): void
    {
        Assert::same($this->store->activeCount('svc'), 0);

        $this->store->tryAcquire('svc', 3, $this->lease);
        $this->store->tryAcquire('svc', 3, $this->lease);

        Assert::same($this->store->activeCount('svc'), 2);
    }

    public function releaseFreesASlot(): void
    {
        $token = $this->store->tryAcquire('svc', 1, $this->lease);
        Assert::true($token !== null);
        Assert::null($this->store->tryAcquire('svc', 1, $this->lease));

        $this->store->release('svc', $token);

        Assert::true($this->store->tryAcquire('svc', 1, $this->lease) !== null);
    }

    public function releaseIsIdempotentForUnknownToken(): void
    {
        $this->store->release('svc', 'never-acquired');

        Assert::same($this->store->activeCount('svc'), 0);
    }

    public function namesAreIsolated(): void
    {
        $this->store->tryAcquire('a', 1, $this->lease);

        Assert::true($this->store->tryAcquire('b', 1, $this->lease) !== null);
        Assert::same($this->store->activeCount('a'), 1);
        Assert::same($this->store->activeCount('b'), 1);
    }

    public function tokensAreUnique(): void
    {
        $first = $this->store->tryAcquire('svc', 2, $this->lease);
        $second = $this->store->tryAcquire('svc', 2, $this->lease);

        Assert::true($first !== $second);
    }

    public function tokenIsSixteenRandomBytesAsHex(): void
    {
        $token = $this->store->tryAcquire('svc', 1, $this->lease);

        Assert::same(strlen((string) $token), 32);
    }

    #[Property(runs: 200)]
    public function neverGrantsMoreThanMaxConcurrent(int $max, int $attempts): void
    {
        $store = new InMemoryBulkheadStore();
        $granted = 0;

        for ($i = 0; $i < $attempts; ++$i) {
            if ($store->tryAcquire('p', $max, $this->lease) !== null) {
                ++$granted;
            }
        }

        Assert::true($granted <= $max);
        Assert::same($store->activeCount('p'), min($attempts, $max));
    }

    /** @return array<string, ArbitraryInterface> */
    private function neverGrantsMoreThanMaxConcurrentGenerators(): array
    {
        return [
            'max' => Gen::intBetween(1, 20),
            'attempts' => Gen::intBetween(0, 40),
        ];
    }
}
