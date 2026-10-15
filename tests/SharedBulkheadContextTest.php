<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Tests;

use Rasuvaeff\Bulkhead\BulkheadFullException;
use Rasuvaeff\Bulkhead\ContextAwareBulkhead;
use Rasuvaeff\Bulkhead\InMemoryBulkheadStore;
use Rasuvaeff\Bulkhead\Internal\ContextBoundBulkhead;
use Rasuvaeff\Bulkhead\Internal\SlotWaiter;
use Rasuvaeff\Bulkhead\SharedBulkhead;
use Rasuvaeff\Bulkhead\Sleeper\FakeSleeper;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextCancelledException;
use Rasuvaeff\Context\ContextController;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Testing\FakeTimeSource;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The bulkhead sleeps through its own SleeperInterface; the budget is counted
 * on the context's TimeSource. The FakeSleeper moves that clock, so both see
 * the same time.
 */
#[Test]
#[Covers(SharedBulkhead::class)]
#[Covers(ContextBoundBulkhead::class)]
#[Covers(SlotWaiter::class)]
final class SharedBulkheadContextTest
{
    private FakeTimeSource $clock;

    private FakeSleeper $sleeper;

    private InMemoryBulkheadStore $store;

    /** @var list<string> */
    private array $rejected = [];

    public function aShortBudgetEndsTheWaitWithTheContextException(): void
    {
        $this->setUp();
        $bulkhead = $this->bulkhead(maxWaitMs: 500, full: true);
        [$context] = Context::background($this->clock)->withTimeout(0.12);

        try {
            $bulkhead->withContext($context)->call(static fn(): int => 1);
        } catch (ContextExpiredException) {
            Assert::same($this->sleptMs(), [50, 50, 20]);
            Assert::same($this->rejected, []);

            return;
        }

        throw new \LogicException('the budget was expected to end the wait');
    }

    public function aShortMaxWaitStillEndsWithBulkheadFull(): void
    {
        $this->setUp();
        $bulkhead = $this->bulkhead(maxWaitMs: 100, full: true);
        [$context] = Context::background($this->clock)->withTimeout(5.0);

        try {
            $bulkhead->withContext($context)->call(static fn(): int => 1);
        } catch (BulkheadFullException) {
            Assert::same($this->sleptMs(), [50, 50]);
            Assert::same($this->rejected, ['svc']);

            return;
        }

        throw new \LogicException('maxWait was expected to end the wait');
    }

    public function aTerminatedContextTakesNoSlot(): void
    {
        $this->setUp();
        $bulkhead = $this->bulkhead(maxWaitMs: 500, full: false);
        [$context, $controller] = Context::background($this->clock)->withCancel();
        $controller->cancel();
        $ran = false;

        try {
            $bulkhead->withContext($context)->call(static function () use (&$ran): void {
                $ran = true;
            });
        } catch (ContextCancelledException) {
            Assert::false($ran);
            Assert::same($this->store->activeCount('svc'), 0);
            Assert::same($this->sleptMs(), []);

            return;
        }

        throw new \LogicException('a cancellation was expected');
    }

    public function aCancellationDuringTheWaitEndsIt(): void
    {
        $this->setUp();
        $controller = null;
        $this->sleeper = new FakeSleeper(onSleep: function (Duration $slept) use (&$controller): void {
            $this->clock->advanceMs($slept->toMillis());
            if ($controller instanceof ContextController) {
                $controller->requestCancel(new \RuntimeException('SIGTERM'));
            }
        });
        $bulkhead = $this->bulkhead(maxWaitMs: 500, full: true);
        [$context, $controller] = Context::background($this->clock)->withCancel();

        try {
            $bulkhead->withContext($context)->call(static fn(): int => 1);
        } catch (ContextCancelledException $exception) {
            Assert::same($exception->getPrevious()?->getMessage(), 'SIGTERM');
            Assert::same($this->sleptMs(), [50]);

            return;
        }

        throw new \LogicException('a cancellation was expected');
    }

    public function aFreeSlotRunsTheCallbackUnderTheContext(): void
    {
        $this->setUp();
        $bulkhead = $this->bulkhead(maxWaitMs: 500, full: false);
        [$context] = Context::background($this->clock)->withTimeout(1.0);
        $view = $bulkhead->withContext($context);

        Assert::same($view->call(static fn(): int => 42), 42);
        Assert::same($view->availableSlots(), 1);
        Assert::same($this->store->activeCount('svc'), 0);
    }

    public function theViewSharesTheSlots(): void
    {
        $this->setUp();
        $bulkhead = $this->bulkhead(maxWaitMs: 500, full: true);
        [$context] = Context::background($this->clock)->withTimeout(1.0);

        Assert::same($bulkhead->withContext($context)->availableSlots(), 0);
        Assert::same($bulkhead->availableSlots(), 0);
    }

    public function theOriginalKeepsWaitingTheFullMaxWait(): void
    {
        $this->setUp();
        $bulkhead = $this->bulkhead(maxWaitMs: 100, full: true);
        [$context] = Context::background($this->clock)->withTimeout(0.01);
        $bulkhead->withContext($context);

        try {
            $bulkhead->call(static fn(): int => 1);
        } catch (BulkheadFullException) {
            Assert::same($this->sleptMs(), [50, 50]);

            return;
        }

        throw new \LogicException('BulkheadFullException was expected');
    }

    public function aSharedBulkheadIsContextAware(): void
    {
        $this->setUp();

        Assert::instanceOf($this->bulkhead(maxWaitMs: 0, full: false), ContextAwareBulkhead::class);
    }

    #[Property(runs: 200)]
    public function theWaitEndsAtWhicheverComesFirst(int $budgetMs, int $maxWaitMs, int $pollMs): void
    {
        $this->setUp();
        $bulkhead = $this->bulkhead(maxWaitMs: $maxWaitMs, full: true, pollMs: $pollMs);
        [$context] = Context::background($this->clock)->withTimeout($budgetMs / 1000.0);
        $budgetFirst = null;

        try {
            $bulkhead->withContext($context)->call(static fn(): int => 1);
        } catch (ContextExpiredException) {
            $budgetFirst = true;
        } catch (BulkheadFullException) {
            $budgetFirst = false;
        }

        Assert::same($budgetFirst, $budgetMs <= $maxWaitMs);
        Assert::same(array_sum($this->sleptMs()), min($budgetMs, $maxWaitMs));
        Assert::same($this->rejected, $budgetFirst ? [] : ['svc']);
        foreach ($this->sleptMs() as $slept) {
            Assert::true($slept >= 1 && $slept <= $pollMs);
        }

        Classify::cover($budgetMs < $maxWaitMs, 'the budget ends first', 20);
        Classify::cover($budgetMs > $maxWaitMs, 'maxWait ends first', 20);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function theWaitEndsAtWhicheverComesFirstGenerators(): array
    {
        return [
            'budgetMs' => Gen::intBetween(1, 400),
            'maxWaitMs' => Gen::intBetween(0, 400),
            'pollMs' => Gen::intBetween(1, 120),
        ];
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function theWaitEndsAtWhicheverComesFirstExamples(): iterable
    {
        yield 'a tie goes to the budget' => [100, 100, 50];
        yield 'fast-fail bulkhead, any budget' => [100, 0, 50];
        yield 'a one-millisecond budget' => [1, 400, 50];
    }

    private function setUp(): void
    {
        $this->clock = new FakeTimeSource();
        $this->sleeper = new FakeSleeper(onSleep: fn(Duration $slept) => $this->clock->advanceMs($slept->toMillis()));
        $this->store = new InMemoryBulkheadStore();
        $this->rejected = [];
    }

    private function bulkhead(int $maxWaitMs, bool $full, int $pollMs = 50): SharedBulkhead
    {
        if ($full) {
            $this->store->tryAcquire('svc', 1, Duration::seconds(60));
        }

        return new SharedBulkhead(
            name: 'svc',
            maxConcurrent: 1,
            store: $this->store,
            lease: Duration::seconds(60),
            maxWait: Duration::millis($maxWaitMs),
            pollInterval: Duration::millis($pollMs),
            sleeper: $this->sleeper,
            onRejected: function (string $name): void {
                $this->rejected[] = $name;
            },
        );
    }

    /** @return list<int> */
    private function sleptMs(): array
    {
        $slept = [];
        foreach ($this->sleeper->slept() as $duration) {
            $slept[] = $duration->toMillis();
        }

        return $slept;
    }
}
