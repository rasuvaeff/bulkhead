<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Tests;

use Rasuvaeff\Bulkhead\BulkheadFullException;
use Rasuvaeff\Bulkhead\BulkheadStore;
use Rasuvaeff\Bulkhead\InMemoryBulkheadStore;
use Rasuvaeff\Bulkhead\SharedBulkhead;
use Rasuvaeff\Bulkhead\Sleeper\FakeSleeper;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(SharedBulkhead::class)]
final class SharedBulkheadTest
{
    public function runsCallbackAndReturnsItsResult(): void
    {
        $bulkhead = $this->bulkhead(maxConcurrent: 1);

        $result = $bulkhead->call(static fn(): int => 42);

        Assert::same($result, 42);
    }

    public function releasesSlotAfterSuccessfulCall(): void
    {
        $bulkhead = $this->bulkhead(maxConcurrent: 1);

        $bulkhead->call(static fn(): int => 1);

        Assert::same($bulkhead->availableSlots(), 1);
    }

    public function releasesSlotWhenCallbackThrows(): void
    {
        $bulkhead = $this->bulkhead(maxConcurrent: 1);

        try {
            $bulkhead->call(static fn(): never => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }

        Assert::same($bulkhead->availableSlots(), 1);
    }

    public function fastFailsWhenFullAndMaxWaitIsZero(): void
    {
        $store = new InMemoryBulkheadStore();
        $store->tryAcquire('svc', 1, Duration::seconds(5));
        $bulkhead = new SharedBulkhead(
            name: 'svc',
            maxConcurrent: 1,
            store: $store,
            lease: Duration::seconds(5),
            maxWait: Duration::zero(),
        );

        Expect::exception(BulkheadFullException::class)->withMessageContaining('is full (max 1 concurrent)');

        $bulkhead->call(static fn(): int => 1);
    }

    public function exposesNameAndLimitOnFullException(): void
    {
        $store = new InMemoryBulkheadStore();
        $store->tryAcquire('svc', 1, Duration::seconds(5));
        $bulkhead = new SharedBulkhead('svc', 1, $store, Duration::seconds(5), Duration::zero());

        try {
            $bulkhead->call(static fn(): int => 1);
            Assert::true(false);
        } catch (BulkheadFullException $e) {
            Assert::same($e->name, 'svc');
            Assert::same($e->maxConcurrent, 1);
        }
    }

    public function invokesOnRejectedWhenFull(): void
    {
        $store = new InMemoryBulkheadStore();
        $store->tryAcquire('svc', 1, Duration::seconds(5));
        $rejected = null;
        $bulkhead = new SharedBulkhead(
            name: 'svc',
            maxConcurrent: 1,
            store: $store,
            lease: Duration::seconds(5),
            maxWait: Duration::zero(),
            onRejected: function (string $name, Duration $waited) use (&$rejected): void {
                $rejected = $name;
            },
        );

        try {
            $bulkhead->call(static fn(): int => 1);
        } catch (BulkheadFullException) {
        }

        Assert::same($rejected, 'svc');
    }

    public function invokesOnAcceptedWhenSlotTaken(): void
    {
        $accepted = null;
        $bulkhead = new SharedBulkhead(
            name: 'svc',
            maxConcurrent: 1,
            store: new InMemoryBulkheadStore(),
            lease: Duration::seconds(5),
            maxWait: Duration::zero(),
            onAccepted: function (string $name, Duration $waited) use (&$accepted): void {
                $accepted = $name;
            },
        );

        $bulkhead->call(static fn(): int => 1);

        Assert::same($accepted, 'svc');
    }

    public function waitsThenAcquiresWhenSlotFreesDuringPolling(): void
    {
        $store = $this->scriptedStore(nullsBeforeToken: 2);
        $sleeper = new FakeSleeper();
        $bulkhead = new SharedBulkhead(
            name: 'svc',
            maxConcurrent: 1,
            store: $store,
            lease: Duration::seconds(5),
            maxWait: Duration::millis(500),
            pollInterval: Duration::millis(50),
            sleeper: $sleeper,
        );

        $result = $bulkhead->call(static fn(): string => 'ok');

        Assert::same($result, 'ok');
        Assert::same(count($sleeper->slept()), 2);
    }

    public function waitBudgetIsBoundedByMaxWait(): void
    {
        $store = $this->scriptedStore(nullsBeforeToken: PHP_INT_MAX);
        $sleeper = new FakeSleeper();
        $bulkhead = new SharedBulkhead(
            name: 'svc',
            maxConcurrent: 1,
            store: $store,
            lease: Duration::seconds(5),
            maxWait: Duration::millis(120),
            pollInterval: Duration::millis(50),
            sleeper: $sleeper,
        );

        try {
            $bulkhead->call(static fn(): int => 1);
            Assert::true(false);
        } catch (BulkheadFullException) {
        }

        // 50 + 50 + 20 (clamped to the remaining budget) = 120ms exactly.
        Assert::same($sleeper->totalSlept()->toMillis(), 120);
    }

    public function availableSlotsReflectsActiveCount(): void
    {
        $store = new InMemoryBulkheadStore();
        $store->tryAcquire('svc', 3, Duration::seconds(5));
        $bulkhead = new SharedBulkhead('svc', 3, $store, Duration::seconds(5), Duration::zero());

        Assert::same($bulkhead->availableSlots(), 2);
    }

    public function availableSlotsIsZeroWhenFull(): void
    {
        $store = new InMemoryBulkheadStore();
        $store->tryAcquire('svc', 2, Duration::seconds(5));
        $store->tryAcquire('svc', 2, Duration::seconds(5));
        $bulkhead = new SharedBulkhead('svc', 2, $store, Duration::seconds(5), Duration::zero());

        Assert::same($bulkhead->availableSlots(), 0);
    }

    public function usesFiftyMillisecondDefaultPollInterval(): void
    {
        $sleeper = new FakeSleeper();
        $bulkhead = new SharedBulkhead(
            name: 'svc',
            maxConcurrent: 1,
            store: $this->scriptedStore(nullsBeforeToken: 1),
            lease: Duration::seconds(5),
            maxWait: Duration::millis(500),
            sleeper: $sleeper,
        );

        $bulkhead->call(static fn(): int => 1);

        Assert::same($sleeper->slept()[0]->toMillis(), 50);
    }

    public function rejectsEmptyName(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Invalid bulkhead name');

        new SharedBulkhead('', 1, new InMemoryBulkheadStore(), Duration::seconds(5), Duration::zero());
    }

    public function rejectsNameWithIllegalCharacters(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Invalid bulkhead name');

        new SharedBulkhead('bad name', 1, new InMemoryBulkheadStore(), Duration::seconds(5), Duration::zero());
    }

    public function rejectsNonPositiveMaxConcurrent(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Max concurrent must be greater than or equal to 1');

        new SharedBulkhead('svc', 0, new InMemoryBulkheadStore(), Duration::seconds(5), Duration::zero());
    }

    public function rejectsZeroLease(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Lease must be greater than zero');

        new SharedBulkhead('svc', 1, new InMemoryBulkheadStore(), Duration::zero(), Duration::zero());
    }

    public function onAcceptedReceivesTimeWaitedForSlot(): void
    {
        $waited = null;
        $bulkhead = new SharedBulkhead(
            name: 'svc',
            maxConcurrent: 1,
            store: $this->scriptedStore(nullsBeforeToken: 2),
            lease: Duration::seconds(5),
            maxWait: Duration::millis(500),
            pollInterval: Duration::millis(50),
            sleeper: new FakeSleeper(),
            onAccepted: function (string $name, Duration $duration) use (&$waited): void {
                $waited = $duration;
            },
        );

        $bulkhead->call(static fn(): int => 1);

        Assert::same($waited?->toMillis(), 100);
    }

    public function onRejectedReceivesTimeWaitedBeforeGivingUp(): void
    {
        $waited = null;
        $bulkhead = new SharedBulkhead(
            name: 'svc',
            maxConcurrent: 1,
            store: $this->scriptedStore(nullsBeforeToken: PHP_INT_MAX),
            lease: Duration::seconds(5),
            maxWait: Duration::millis(120),
            pollInterval: Duration::millis(50),
            sleeper: new FakeSleeper(),
            onRejected: function (string $name, Duration $duration) use (&$waited): void {
                $waited = $duration;
            },
        );

        try {
            $bulkhead->call(static fn(): int => 1);
        } catch (BulkheadFullException) {
        }

        Assert::same($waited?->toMillis(), 120);
    }

    public function exposesNameAndMaxConcurrent(): void
    {
        $bulkhead = $this->bulkhead(maxConcurrent: 3);

        Assert::same($bulkhead->name(), 'svc');
        Assert::same($bulkhead->maxConcurrent(), 3);
    }

    public function jitterKeepsPollSleepsWithinConfiguredBand(): void
    {
        $sleeper = new FakeSleeper();
        $bulkhead = new SharedBulkhead(
            name: 'svc',
            maxConcurrent: 1,
            store: $this->scriptedStore(nullsBeforeToken: PHP_INT_MAX),
            lease: Duration::seconds(5),
            maxWait: Duration::millis(500),
            pollInterval: Duration::millis(50),
            pollJitter: 0.5,
            sleeper: $sleeper,
        );

        try {
            $bulkhead->call(static fn(): int => 1);
            Assert::true(false);
        } catch (BulkheadFullException) {
        }

        Assert::same($sleeper->totalSlept()->toMillis(), 500);

        $sleeps = $sleeper->slept();
        $last = array_pop($sleeps);
        Assert::true($last !== null && $last->toMicros() <= 75_000);

        $jittered = false;
        foreach ($sleeps as $sleep) {
            Assert::true($sleep->toMicros() >= 25_000);
            Assert::true($sleep->toMicros() <= 75_000);
            $jittered = $jittered || $sleep->toMicros() !== 50_000;
        }

        // ~10 draws from ±25000µs all landing on exactly 50ms is (1/50001)^10.
        Assert::true($jittered);
    }

    public function fullPollJitterIsAcceptedAndStillJitters(): void
    {
        $sleeper = new FakeSleeper();
        $bulkhead = new SharedBulkhead(
            name: 'svc',
            maxConcurrent: 1,
            store: $this->scriptedStore(nullsBeforeToken: PHP_INT_MAX),
            lease: Duration::seconds(5),
            maxWait: Duration::millis(500),
            pollInterval: Duration::millis(50),
            pollJitter: 1.0,
            sleeper: $sleeper,
        );

        try {
            $bulkhead->call(static fn(): int => 1);
            Assert::true(false);
        } catch (BulkheadFullException) {
        }

        Assert::same($sleeper->totalSlept()->toMillis(), 500);

        $jittered = false;
        foreach ($sleeper->slept() as $sleep) {
            $jittered = $jittered || $sleep->toMicros() !== 50_000;
        }

        Assert::true($jittered);
    }

    public function jitteredSleepIsNeverBelowOneMicrosecond(): void
    {
        $sleeper = new FakeSleeper();
        $bulkhead = new SharedBulkhead(
            name: 'svc',
            maxConcurrent: 1,
            store: $this->scriptedStore(nullsBeforeToken: PHP_INT_MAX),
            lease: Duration::seconds(5),
            maxWait: Duration::micros(50),
            pollInterval: Duration::micros(1),
            pollJitter: 1.0,
            sleeper: $sleeper,
        );

        try {
            $bulkhead->call(static fn(): int => 1);
            Assert::true(false);
        } catch (BulkheadFullException) {
        }

        $min = PHP_INT_MAX;
        foreach ($sleeper->slept() as $sleep) {
            Assert::true($sleep->toMicros() >= 1);
            $min = min($min, $sleep->toMicros());
        }

        // With ±1µs jitter on a 1µs interval the 1µs floor is hit almost surely
        // within ~50 draws; a raised floor (max(2, ...)) would never produce it.
        Assert::same($min, 1);
    }

    public function rejectsNegativePollJitter(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Poll jitter must be between 0 and 1');

        new SharedBulkhead(
            name: 'svc',
            maxConcurrent: 1,
            store: new InMemoryBulkheadStore(),
            lease: Duration::seconds(5),
            maxWait: Duration::zero(),
            pollJitter: -0.1,
        );
    }

    public function rejectsPollJitterAboveOne(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Poll jitter must be between 0 and 1');

        new SharedBulkhead(
            name: 'svc',
            maxConcurrent: 1,
            store: new InMemoryBulkheadStore(),
            lease: Duration::seconds(5),
            maxWait: Duration::zero(),
            pollJitter: 1.1,
        );
    }

    public function rejectsZeroPollInterval(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Poll interval must be greater than zero');

        new SharedBulkhead(
            name: 'svc',
            maxConcurrent: 1,
            store: new InMemoryBulkheadStore(),
            lease: Duration::seconds(5),
            maxWait: Duration::millis(100),
            pollInterval: Duration::zero(),
        );
    }

    #[Property(runs: 150)]
    public function waitBudgetNeverExceedsMaxWait(int $maxWaitMillis, int $pollMillis): void
    {
        $sleeper = new FakeSleeper();
        $bulkhead = new SharedBulkhead(
            name: 'svc',
            maxConcurrent: 1,
            store: $this->scriptedStore(nullsBeforeToken: PHP_INT_MAX),
            lease: Duration::seconds(5),
            maxWait: Duration::millis($maxWaitMillis),
            pollInterval: Duration::millis($pollMillis),
            sleeper: $sleeper,
        );

        try {
            $bulkhead->call(static fn(): int => 1);
            Assert::true(false);
        } catch (BulkheadFullException) {
        }

        Assert::same($sleeper->totalSlept()->toMillis(), $maxWaitMillis);

        foreach ($sleeper->slept() as $sleep) {
            Assert::true($sleep->toMillis() <= $pollMillis);
        }
    }

    /** @return array<string, ArbitraryInterface> */
    private function waitBudgetNeverExceedsMaxWaitGenerators(): array
    {
        return [
            'maxWaitMillis' => Gen::intBetween(0, 2000),
            'pollMillis' => Gen::intBetween(10, 200),
        ];
    }

    private function bulkhead(int $maxConcurrent): SharedBulkhead
    {
        return new SharedBulkhead(
            name: 'svc',
            maxConcurrent: $maxConcurrent,
            store: new InMemoryBulkheadStore(),
            lease: Duration::seconds(5),
            maxWait: Duration::zero(),
        );
    }

    private function scriptedStore(int $nullsBeforeToken): BulkheadStore
    {
        return new class ($nullsBeforeToken) implements BulkheadStore {
            private int $attempts = 0;

            public function __construct(
                private readonly int $nullsBeforeToken,
            ) {}

            #[\Override]
            public function tryAcquire(string $name, int $maxConcurrent, Duration $lease): ?string
            {
                $current = $this->attempts;
                ++$this->attempts;

                return $current < $this->nullsBeforeToken ? null : 'token';
            }

            #[\Override]
            public function release(string $name, string $token): void {}

            #[\Override]
            public function activeCount(string $name): int
            {
                return 0;
            }
        };
    }
}
