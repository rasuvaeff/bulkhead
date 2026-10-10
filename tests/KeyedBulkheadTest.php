<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Tests;

use Rasuvaeff\Bulkhead\BulkheadFullException;
use Rasuvaeff\Bulkhead\InMemoryBulkheadStore;
use Rasuvaeff\Bulkhead\Internal\Limits;
use Rasuvaeff\Bulkhead\Internal\SlotWaiter;
use Rasuvaeff\Bulkhead\KeyedBulkhead;
use Rasuvaeff\Bulkhead\Sleeper\FakeSleeper;
use Rasuvaeff\Duration\Duration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(KeyedBulkhead::class)]
#[Covers(SlotWaiter::class)]
#[Covers(Limits::class)]
final class KeyedBulkheadTest
{
    public function runsCallbackUnderThePerCallNameAndLimit(): void
    {
        $store = new InMemoryBulkheadStore();
        $bulkhead = $this->bulkhead($store);
        $during = null;

        $result = $bulkhead->call('proxy-1', 3, static function () use ($store, &$during): string {
            $during = $store->activeCount('proxy-1');

            return 'ok';
        });

        Assert::same($result, 'ok');
        Assert::same($during, 1);
        Assert::same($store->activeCount('proxy-1'), 0);
    }

    public function releasesTheSlotWhenTheCallbackThrows(): void
    {
        $store = new InMemoryBulkheadStore();
        $bulkhead = $this->bulkhead($store);

        try {
            $bulkhead->call('proxy-1', 1, static fn(): never => throw new \DomainException('boom'));
            Assert::true(actual: false);
        } catch (\DomainException $e) {
            Assert::same($e->getMessage(), 'boom');
        }

        Assert::same($store->activeCount('proxy-1'), 0);
    }

    public function namesAreIndependentBulkheads(): void
    {
        $store = new InMemoryBulkheadStore();
        $bulkhead = $this->bulkhead($store);

        $held = $bulkhead->acquire('proxy-1', 1);
        $other = $bulkhead->call('proxy-2', 1, static fn(): string => 'proxy-2 still free');

        Assert::same($other, 'proxy-2 still free');
        Assert::same($held->name(), 'proxy-1');
        Assert::same($bulkhead->availableSlots('proxy-1', 1), 0);
        Assert::same($bulkhead->availableSlots('proxy-2', 1), 1);
    }

    public function throwsFullWithTheCallsNameLimitAndHint(): void
    {
        $store = new InMemoryBulkheadStore();
        $bulkhead = new KeyedBulkhead(
            store: $store,
            lease: Duration::seconds(9),
            maxWait: Duration::millis(100),
            pollInterval: Duration::millis(40),
            sleeper: new FakeSleeper(),
        );
        $bulkhead->acquire('proxy-1', 2);
        $bulkhead->acquire('proxy-1', 2);
        $caught = null;

        try {
            $bulkhead->call('proxy-1', 2, static fn(): int => 1);
        } catch (BulkheadFullException $e) {
            $caught = $e;
        }

        Assert::same($caught?->name, 'proxy-1');
        Assert::same($caught?->maxConcurrent, 2);
        Assert::same($caught?->waited?->toMillis(), 100);
        Assert::same($caught?->retryAfter()?->toMillis(), 9_000);
    }

    public function acquiredSlotIsReleasedElsewhere(): void
    {
        $store = new InMemoryBulkheadStore();
        $bulkhead = $this->bulkhead($store);

        $slot = $bulkhead->acquire('proxy-1', 1);
        Assert::null($bulkhead->tryAcquire('proxy-1', 1));

        $slot->release();
        $slot->release();

        Assert::same($store->activeCount('proxy-1'), 0);
        Assert::same($bulkhead->tryAcquire('proxy-1', 1)?->name(), 'proxy-1');
    }

    public function observersReceiveThePerCallName(): void
    {
        $events = [];
        $store = new InMemoryBulkheadStore();
        $bulkhead = new KeyedBulkhead(
            store: $store,
            lease: Duration::seconds(5),
            maxWait: Duration::zero(),
            onAccepted: static function (string $name, Duration $waited) use (&$events): void {
                $events[] = "accepted:$name";
            },
            onRejected: static function (string $name, Duration $waited) use (&$events): void {
                $events[] = "rejected:$name";
            },
        );

        $bulkhead->acquire('a', 1);
        $bulkhead->tryAcquire('a', 1);
        $bulkhead->tryAcquire('b', 1);

        Assert::same($events, ['accepted:a', 'rejected:a', 'accepted:b']);
    }

    public function availableSlotsNeverNegative(): void
    {
        $store = new InMemoryBulkheadStore();
        $bulkhead = $this->bulkhead($store);
        $bulkhead->acquire('proxy-1', 3);
        $bulkhead->acquire('proxy-1', 3);

        Assert::same($bulkhead->availableSlots('proxy-1', 3), 1);
        Assert::same($bulkhead->availableSlots('proxy-1', 1), 0);
    }

    #[DataProvider('invalidIdentityProvider')]
    public function callRejectsInvalidIdentity(string $name, int $maxConcurrent, string $message): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining($message);

        $this->bulkhead(new InMemoryBulkheadStore())->call($name, $maxConcurrent, static fn(): int => 1);
    }

    #[DataProvider('invalidIdentityProvider')]
    public function acquireRejectsInvalidIdentity(string $name, int $maxConcurrent, string $message): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining($message);

        $this->bulkhead(new InMemoryBulkheadStore())->acquire($name, $maxConcurrent);
    }

    #[DataProvider('invalidIdentityProvider')]
    public function tryAcquireRejectsInvalidIdentity(string $name, int $maxConcurrent, string $message): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining($message);

        $this->bulkhead(new InMemoryBulkheadStore())->tryAcquire($name, $maxConcurrent);
    }

    #[DataProvider('invalidIdentityProvider')]
    public function availableSlotsRejectsInvalidIdentity(string $name, int $maxConcurrent, string $message): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining($message);

        $this->bulkhead(new InMemoryBulkheadStore())->availableSlots($name, $maxConcurrent);
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function invalidIdentityProvider(): iterable
    {
        yield 'empty name' => ['', 1, 'Invalid bulkhead name ""'];
        yield 'space in name' => ['bad name', 1, 'Invalid bulkhead name "bad name"'];
        yield 'trailing newline' => ["svc\n", 1, 'Invalid bulkhead name'];
        yield 'zero limit' => ['svc', 0, 'Max concurrent must be greater than or equal to 1'];
    }

    public function rejectsZeroLease(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Lease must be greater than zero');

        new KeyedBulkhead(store: new InMemoryBulkheadStore(), lease: Duration::zero(), maxWait: Duration::zero());
    }

    private function bulkhead(InMemoryBulkheadStore $store): KeyedBulkhead
    {
        return new KeyedBulkhead(store: $store, lease: Duration::seconds(5), maxWait: Duration::zero());
    }
}
