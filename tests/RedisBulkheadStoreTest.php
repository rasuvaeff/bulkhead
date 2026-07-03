<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Tests;

use Rasuvaeff\Bulkhead\BulkheadScriptRunner;
use Rasuvaeff\Bulkhead\RedisBulkheadStore;
use Rasuvaeff\Duration\Duration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RedisBulkheadStore::class)]
final class RedisBulkheadStoreTest
{
    public function returnsTokenWhenScriptReportsAcquired(): void
    {
        $runner = $this->recordingRunner(reply: 1);
        $store = new RedisBulkheadStore($runner);

        $token = $store->tryAcquire('svc', 2, Duration::seconds(5));

        Assert::true($token !== null);
        Assert::same(strlen($token), 32);
        Assert::same($runner->key, 'bulkhead:svc');
        Assert::same($runner->args, [2, 5000, $token]);
    }

    public function returnsNullWhenScriptReportsFull(): void
    {
        $store = new RedisBulkheadStore($this->recordingRunner(reply: 0));

        Assert::null($store->tryAcquire('svc', 2, Duration::seconds(5)));
    }

    public function releasePassesTokenAsScriptArgument(): void
    {
        $runner = $this->recordingRunner(reply: 1);
        $store = new RedisBulkheadStore($runner);

        $store->release('svc', 'abc123');

        Assert::same($runner->key, 'bulkhead:svc');
        Assert::same($runner->args, ['abc123']);
    }

    public function activeCountReturnsScriptReply(): void
    {
        $store = new RedisBulkheadStore($this->recordingRunner(reply: 3));

        Assert::same($store->activeCount('svc'), 3);
    }

    public function activeCountClampsNegativeReplyToZero(): void
    {
        $store = new RedisBulkheadStore($this->recordingRunner(reply: -1));

        Assert::same($store->activeCount('svc'), 0);
    }

    public function usesCustomKeyPrefix(): void
    {
        $runner = $this->recordingRunner(reply: 1);
        $store = new RedisBulkheadStore($runner, 'custom:');

        $store->activeCount('svc');

        Assert::same($runner->key, 'custom:svc');
    }

    /**
     * @return BulkheadScriptRunner&object{script: ?string, key: ?string, args: array}
     */
    private function recordingRunner(int $reply): object
    {
        return new class ($reply) implements BulkheadScriptRunner {
            public ?string $script = null;
            public ?string $key = null;
            /** @var list<int|string> */
            public array $args = [];

            public function __construct(
                private readonly int $reply,
            ) {}

            #[\Override]
            public function run(string $script, string $key, array $args): int
            {
                $this->script = $script;
                $this->key = $key;
                $this->args = $args;

                return $this->reply;
            }
        };
    }
}
