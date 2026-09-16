<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Tests;

use Rasuvaeff\Bulkhead\InMemoryBulkheadStore;
use Rasuvaeff\Bulkhead\Tests\Support\AcquireCommand;
use Rasuvaeff\Bulkhead\Tests\Support\BulkheadHarness;
use Rasuvaeff\Bulkhead\Tests\Support\ReleaseCommand;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
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

    public function activeCountsReportsEveryNameAndZeroForUnknownOnes(): void
    {
        $this->store->tryAcquire('a', 3, $this->lease);
        $this->store->tryAcquire('a', 3, $this->lease);
        $this->store->tryAcquire('b', 3, $this->lease);

        Assert::same($this->store->activeCounts(['a', 'b', 'never-seen']), ['a' => 2, 'b' => 1, 'never-seen' => 0]);
    }

    public function activeCountsCollapsesDuplicateNamesInFirstSeenOrder(): void
    {
        $this->store->tryAcquire('b', 1, $this->lease);

        Assert::same($this->store->activeCounts(['b', 'a', 'b', 'a']), ['b' => 1, 'a' => 0]);
    }

    public function activeCountsKeysNumericNamesAsIntsLikeAnyPhpArray(): void
    {
        $this->store->tryAcquire('42', 1, $this->lease);

        Assert::same($this->store->activeCounts(['42', 'a', '7']), [42 => 1, 'a' => 0, 7 => 0]);
    }

    public function activeCountsOfNoNamesIsEmpty(): void
    {
        Assert::same($this->store->activeCounts([]), []);
    }

    public function activeCountsDoesNotTouchSlots(): void
    {
        $this->store->tryAcquire('a', 1, $this->lease);

        $this->store->activeCounts(['a', 'b']);

        Assert::null($this->store->tryAcquire('a', 1, $this->lease));
        Assert::same($this->store->activeCount('b'), 0);
    }

    /**
     * The batch snapshot is the per-name count, name by name: same values,
     * each name once, first-seen order — for any acquire pattern and any mix
     * of known, unknown and repeated names.
     */
    #[Property(runs: 200, timeoutMs: 1000)]
    public function activeCountsAgreesWithActiveCountForEveryName(array $held, array $names): void
    {
        $store = new InMemoryBulkheadStore();

        // Numeric-string names are already int keys here (PHP array semantics),
        // which is exactly what the result must mirror.
        /** @var array<array-key, int<0, max>> $held */
        foreach ($held as $name => $slots) {
            for ($i = 0; $i < $slots; ++$i) {
                $store->tryAcquire((string) $name, PHP_INT_MAX, $this->lease);
            }
        }

        /** @var list<non-empty-string> $names */
        $unique = array_values(array_unique($names));
        Classify::cover(count($unique) < count($names), 'repeated names', 20.0);
        Classify::cover(array_diff($unique, array_keys($held)) !== [], 'names never acquired', 20.0);
        Classify::cover(count($names) >= 100, '100 or more names', 5.0);

        $expected = [];
        foreach ($unique as $name) {
            $expected[$name] = $store->activeCount($name);
        }

        // Keys of the batch result must be usable as names again after a cast.
        Classify::cover(array_keys($expected) !== $unique, 'a numeric name became an int key', 10.0);

        Assert::same($store->activeCounts($names), $expected);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function activeCountsAgreesWithActiveCountForEveryNameGenerators(): array
    {
        $name = Gen::stringFrom('abcdefgh0123', 1, 2);

        return [
            'held' => Gen::dictOf($name, Gen::intBetween(0, 4), 0, 6),
            'names' => Gen::arrayOf($name, 0, 120),
        ];
    }

    /** @return iterable<string, array{array<string, int>, list<string>}> */
    public static function activeCountsAgreesWithActiveCountForEveryNameExamples(): iterable
    {
        yield 'empty input' => [['a' => 1], []];
        yield 'only unknown names' => [[], ['x', 'y']];
        yield 'every name repeated' => [['a' => 2, 'b' => 1], ['b', 'a', 'b', 'a']];
        yield '120 names' => [['p7' => 3], array_map(static fn(int $i): string => 'p' . $i, range(0, 119))];
        yield 'numeric names become int keys' => [['42' => 1], ['42', '0', '007', 'a']];
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

    #[Property(runs: 200, timeoutMs: 1000)]
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

    #[Property(runs: 150, timeoutMs: 1000)]
    public function releasingEveryTokenRestoresFullCapacity(int $max): void
    {
        $store = new InMemoryBulkheadStore();

        // Capped at $max + 1 attempts on purpose: timeoutMs is checked after the
        // body returns, so a store that keeps granting would hang an unbounded
        // loop instead of failing the count assertion below.
        $tokens = [];
        for ($attempt = 0; $attempt <= $max; ++$attempt) {
            $token = $store->tryAcquire('svc', $max, $this->lease);

            if ($token === null) {
                break;
            }

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
    #[Property(runs: 300, timeoutMs: 1000)]
    public function interleavedAcquireAndReleaseTrackTheModel(CommandSequence $sequence): void
    {
        $harness = new BulkheadHarness(3);

        $kinds = [];

        foreach ($sequence->commands as $command) {
            $kinds[$command::class] = true;
        }

        // Swarming is what makes these shares worth gating: drawing uniformly
        // from both commands, a sequence that never releases is astronomically
        // rare, and saturation — every slot held, every further acquire
        // refused — is precisely where a leased-slot store goes wrong.
        Classify::cover(
            isset($kinds[AcquireCommand::class]) && !isset($kinds[ReleaseCommand::class]),
            'acquire-only, driven to saturation',
            10.0,
        );
        Classify::cover(\count($kinds) === 2, 'both commands interleaved', 15.0);
        Classify::when($sequence->commands === [], 'subset with no applicable command');

        // check() throws PostconditionViolation the moment a command disagrees
        // with the model; reaching the final assertion means every step matched.
        StateMachine::check($sequence, static fn(): BulkheadHarness => $harness);

        Assert::true($harness->activeCount() >= 0 && $harness->activeCount() <= 3);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function interleavedAcquireAndReleaseTrackTheModelGenerators(): array
    {
        $max = 3;

        // Swarmed: each sequence may use only a subset of the two commands,
        // drawn afresh per case. Without it every sequence mixes both, and the
        // bugs that need an operation to be absent stay out of reach.
        // minLength stays at the default 0, so a subset from which nothing
        // applies yields an empty sequence rather than GenerationExhausted.
        return ['sequence' => Gen::swarm(Gen::commands(0, [
            Gen::constant(new AcquireCommand($max)),
            Gen::map(Gen::intBetween(0, $max - 1), static fn(int $index): ReleaseCommand => new ReleaseCommand($index)),
        ]))];
    }
}
