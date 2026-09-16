<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Tests;

use Rasuvaeff\Bulkhead\BulkheadMultiKeyScriptRunner;
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

    public function activeCountsSendsEveryKeyToOneScriptCall(): void
    {
        $runner = $this->multiKeyRunner(replies: [1, 3]);
        $store = new RedisBulkheadStore($runner);

        $counts = $store->activeCounts(['a', 'b']);

        Assert::same($counts, ['a' => 1, 'b' => 3]);
        Assert::same($runner->calls, [[['bulkhead:a', 'bulkhead:b'], []]]);
        Assert::same($runner->singleKeyCalls, 0);
    }

    public function activeCountsCollapsesDuplicateNamesBeforeTheRoundTrip(): void
    {
        $runner = $this->multiKeyRunner(replies: [2, 5]);
        $store = new RedisBulkheadStore($runner);

        // A repeat *before* the next distinct name is what breaks a naive
        // dedupe: array_unique() keeps the original keys (0, 2), and the reply
        // list is positional.
        $counts = $store->activeCounts(['a', 'a', 'b', 'a']);

        Assert::same($counts, ['a' => 2, 'b' => 5]);
        Assert::same($runner->calls, [[['bulkhead:a', 'bulkhead:b'], []]]);
    }

    public function activeCountsClampsNegativeRepliesAndFillsMissingOnesWithZero(): void
    {
        $store = new RedisBulkheadStore($this->multiKeyRunner(replies: [-1]));

        Assert::same($store->activeCounts(['a', 'b']), ['a' => 0, 'b' => 0]);
    }

    public function activeCountsOfNoNamesMakesNoRoundTrip(): void
    {
        $runner = $this->multiKeyRunner(replies: [7]);
        $store = new RedisBulkheadStore($runner);

        Assert::same($store->activeCounts([]), []);
        Assert::same($runner->calls, []);
    }

    public function activeCountsUsesTheCustomKeyPrefix(): void
    {
        $runner = $this->multiKeyRunner(replies: [0]);
        $store = new RedisBulkheadStore($runner, 'custom:');

        $store->activeCounts(['svc']);

        Assert::same($runner->calls, [[['custom:svc'], []]]);
    }

    public function activeCountsFallsBackToOneCallPerNameWithoutAMultiKeyRunner(): void
    {
        $runner = $this->recordingRunner(reply: 2);
        $store = new RedisBulkheadStore($runner);

        $counts = $store->activeCounts(['a', 'b', 'a']);

        Assert::same($counts, ['a' => 2, 'b' => 2]);
        Assert::same($runner->keys, ['bulkhead:a', 'bulkhead:b']);
    }

    public function activeCountsFallbackClampsNegativeRepliesToZero(): void
    {
        $store = new RedisBulkheadStore($this->recordingRunner(reply: -1));

        Assert::same($store->activeCounts(['a']), ['a' => 0]);
    }

    /**
     * @param list<int> $replies
     *
     * @return BulkheadMultiKeyScriptRunner&object{calls: list<array{list<string>, list<int|string>}>, singleKeyCalls: int}
     */
    private function multiKeyRunner(array $replies): object
    {
        return new class ($replies) implements BulkheadMultiKeyScriptRunner {
            /** @var list<array{list<string>, list<int|string>}> */
            public array $calls = [];
            public int $singleKeyCalls = 0;

            /** @param list<int> $replies */
            public function __construct(
                private readonly array $replies,
            ) {}

            #[\Override]
            public function run(string $script, string $key, array $args): int
            {
                ++$this->singleKeyCalls;

                return 0;
            }

            #[\Override]
            public function runMany(string $script, array $keys, array $args): array
            {
                $this->calls[] = [$keys, $args];

                return $this->replies;
            }
        };
    }

    /**
     * @return BulkheadScriptRunner&object{script: ?string, key: ?string, keys: list<string>, args: array}
     */
    private function recordingRunner(int $reply): object
    {
        return new class ($reply) implements BulkheadScriptRunner {
            public ?string $script = null;
            public ?string $key = null;
            /** @var list<string> */
            public array $keys = [];
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
                $this->keys[] = $key;
                $this->args = $args;

                return $this->reply;
            }
        };
    }
}
