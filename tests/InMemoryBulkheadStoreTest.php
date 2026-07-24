<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Tests;

use Rasuvaeff\Bulkhead\InMemoryBulkheadStore;
use Rasuvaeff\Bulkhead\Tests\Support\AcquireCommand;
use Rasuvaeff\Bulkhead\Tests\Support\BulkheadHarness;
use Rasuvaeff\Bulkhead\Tests\Support\ReleaseCommand;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\StateMachine\CommandSequence;
use Rasuvaeff\PropertyTesting\StateMachine\StateMachine;
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
    public static function neverGrantsMoreThanMaxConcurrentGenerators(): array
    {
        return [
            'max' => Gen::intBetween(1, 20),
            'attempts' => Gen::intBetween(0, 40),
        ];
    }

    #[Property(runs: 150)]
    public function releasingEveryTokenRestoresFullCapacity(int $max): void
    {
        $store = new InMemoryBulkheadStore();

        $tokens = [];
        while (($token = $store->tryAcquire('svc', $max, $this->lease)) !== null) {
            $tokens[] = $token;
        }

        Assert::same(count($tokens), $max);
        Assert::same($store->activeCount('svc'), $max);

        foreach ($tokens as $token) {
            $store->release('svc', $token);
        }

        Assert::same($store->activeCount('svc'), 0);
        Assert::true($store->tryAcquire('svc', $max, $this->lease) !== null);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function releasingEveryTokenRestoresFullCapacityGenerators(): array
    {
        return ['max' => Gen::intBetween(1, 30)];
    }

    /**
     * Model-based test: any interleaving of acquire and release stays in step
     * with a simplified model (a held-slot count), so `activeCount` never leaves
     * `[0, max]` and matches the count implied by the operations — coverage the
     * single-shot properties above (acquire-only, acquire-all-then-release-all)
     * never reach.
     */
    #[Property(runs: 300)]
    public function interleavedAcquireAndReleaseTrackTheModel(CommandSequence $sequence): void
    {
        $harness = new BulkheadHarness(3);

        // check() throws PostconditionViolation the moment a command disagrees
        // with the model; reaching the final assertion means every step matched.
        StateMachine::check($sequence, static fn(): BulkheadHarness => $harness);

        Assert::true($harness->activeCount() >= 0 && $harness->activeCount() <= 3);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function interleavedAcquireAndReleaseTrackTheModelGenerators(): array
    {
        $max = 3;

        return ['sequence' => Gen::commands(0, [
            Gen::constant(new AcquireCommand($max)),
            Gen::map(Gen::intBetween(0, $max - 1), static fn(int $index): ReleaseCommand => new ReleaseCommand($index)),
        ])];
    }
}
