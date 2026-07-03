<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Tests\Integration;

use Rasuvaeff\Bulkhead\Redis\PhpRedisScriptRunner;
use Rasuvaeff\Bulkhead\RedisBulkheadStore;
use Rasuvaeff\Duration\Duration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(PhpRedisScriptRunner::class)]
final class PhpRedisIntegrationTest
{
    private const string NAME = 'it-phpredis';

    private RedisBulkheadStore $store;
    private \Redis $client;

    #[BeforeTest]
    public function setUp(): void
    {
        if (!extension_loaded('redis')) {
            return;
        }

        $host = getenv('REDIS_HOST');
        if ($host === false || $host === '') {
            return;
        }

        $port = getenv('REDIS_PORT');
        $this->client = new \Redis();
        $this->client->connect($host, $port === false || $port === '' ? 6379 : (int) $port);
        $this->client->flushDB();
        $this->store = new RedisBulkheadStore(new PhpRedisScriptRunner($this->client));
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
        Assert::same($this->store->activeCount(self::NAME), 2);
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

    public function acquireSurvivesAFlushedScriptCache(): void
    {
        if (!isset($this->store)) {
            return;
        }

        $this->client->script('FLUSH');

        Assert::true($this->store->tryAcquire(self::NAME, 1, Duration::seconds(30)) !== null);
    }
}
