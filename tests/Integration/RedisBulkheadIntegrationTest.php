<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Tests\Integration;

use Predis\Client;
use Rasuvaeff\Bulkhead\Redis\PredisScriptRunner;
use Rasuvaeff\Bulkhead\RedisBulkheadStore;
use Rasuvaeff\Duration\Duration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(RedisBulkheadStore::class)]
#[Covers(PredisScriptRunner::class)]
final class RedisBulkheadIntegrationTest
{
    private const string NAME = 'it';

    private RedisBulkheadStore $store;
    private Client $client;

    #[BeforeTest]
    public function setUp(): void
    {
        $host = getenv('REDIS_HOST');
        if ($host === false || $host === '') {
            return;
        }

        $port = getenv('REDIS_PORT');
        $this->client = new Client([
            'host' => $host,
            'port' => $port === false || $port === '' ? 6379 : (int) $port,
        ]);
        $this->client->flushdb();
        $this->store = new RedisBulkheadStore(new PredisScriptRunner($this->client));
    }

    public function acquiresUpToMaxThenReturnsNull(): void
    {
        if (!isset($this->store)) {
            return;
        }

        $lease = Duration::seconds(30);

        $first = $this->store->tryAcquire(self::NAME, 2, $lease);
        $second = $this->store->tryAcquire(self::NAME, 2, $lease);
        $third = $this->store->tryAcquire(self::NAME, 2, $lease);

        Assert::true($first !== null);
        Assert::true($second !== null);
        Assert::null($third);
        Assert::same(strlen((string) $first), 32);
        Assert::same($this->store->activeCount(self::NAME), 2);
    }

    public function namesAreIsolated(): void
    {
        if (!isset($this->store)) {
            return;
        }

        $lease = Duration::seconds(30);

        Assert::true($this->store->tryAcquire('name-a', 1, $lease) !== null);
        Assert::true($this->store->tryAcquire('name-b', 1, $lease) !== null);
        Assert::same($this->store->activeCount('name-a'), 1);
        Assert::same($this->store->activeCount('name-b'), 1);
    }

    public function differentKeyPrefixesAreIsolated(): void
    {
        if (!isset($this->store)) {
            return;
        }

        $lease = Duration::seconds(30);
        $runner = new PredisScriptRunner($this->client);
        $storeA = new RedisBulkheadStore($runner, 'a:');
        $storeB = new RedisBulkheadStore($runner, 'b:');

        Assert::true($storeA->tryAcquire(self::NAME, 1, $lease) !== null);
        Assert::true($storeB->tryAcquire(self::NAME, 1, $lease) !== null);
        Assert::same($storeA->activeCount(self::NAME), 1);
        Assert::same($storeB->activeCount(self::NAME), 1);
    }

    public function releaseFreesASlot(): void
    {
        if (!isset($this->store)) {
            return;
        }

        $lease = Duration::seconds(30);
        $token = $this->store->tryAcquire(self::NAME, 1, $lease);
        Assert::true($token !== null);
        Assert::null($this->store->tryAcquire(self::NAME, 1, $lease));

        $this->store->release(self::NAME, $token);

        Assert::same($this->store->activeCount(self::NAME), 0);
        Assert::true($this->store->tryAcquire(self::NAME, 1, $lease) !== null);
    }

    public function expiredLeaseIsReclaimed(): void
    {
        if (!isset($this->store)) {
            return;
        }

        $shortLease = Duration::millis(100);
        Assert::true($this->store->tryAcquire(self::NAME, 1, $shortLease) !== null);
        Assert::null($this->store->tryAcquire(self::NAME, 1, $shortLease));

        usleep(200_000);

        Assert::same($this->store->activeCount(self::NAME), 0);
        Assert::true($this->store->tryAcquire(self::NAME, 1, $shortLease) !== null);
    }
}
