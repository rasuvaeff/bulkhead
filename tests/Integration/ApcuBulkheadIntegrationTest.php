<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Tests\Integration;

use Rasuvaeff\Bulkhead\ApcuBulkheadStore;
use Rasuvaeff\Duration\Duration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(ApcuBulkheadStore::class)]
final class ApcuBulkheadIntegrationTest
{
    private const string NAME = 'it';

    private ?ApcuBulkheadStore $store = null;

    #[BeforeTest]
    public function setUp(): void
    {
        if (!ApcuBulkheadStore::isAvailable()) {
            return;
        }

        apcu_clear_cache();
        $this->store = new ApcuBulkheadStore();
    }

    public function acquiresUpToMaxThenReturnsNull(): void
    {
        if ($this->store === null) {
            return;
        }

        $lease = Duration::seconds(30);

        $first = $this->store->tryAcquire(self::NAME, 2, $lease);
        $second = $this->store->tryAcquire(self::NAME, 2, $lease);
        $third = $this->store->tryAcquire(self::NAME, 2, $lease);

        Assert::true($first !== null);
        Assert::true($second !== null);
        Assert::null($third);
        Assert::same(strlen($first), 32);
        Assert::same($this->store->activeCount(self::NAME), 2);
    }

    public function namesAreIsolated(): void
    {
        if ($this->store === null) {
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
        if ($this->store === null) {
            return;
        }

        $lease = Duration::seconds(30);
        $storeA = new ApcuBulkheadStore('a:');
        $storeB = new ApcuBulkheadStore('b:');

        Assert::true($storeA->tryAcquire(self::NAME, 1, $lease) !== null);
        Assert::true($storeB->tryAcquire(self::NAME, 1, $lease) !== null);
        Assert::same($storeA->activeCount(self::NAME), 1);
        Assert::same($storeB->activeCount(self::NAME), 1);
    }

    public function releaseFreesASlot(): void
    {
        if ($this->store === null) {
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
        if ($this->store === null) {
            return;
        }

        $shortLease = Duration::millis(100);
        Assert::true($this->store->tryAcquire(self::NAME, 1, $shortLease) !== null);
        Assert::null($this->store->tryAcquire(self::NAME, 1, $shortLease));

        usleep(200_000);

        Assert::same($this->store->activeCount(self::NAME), 0);
        Assert::true($this->store->tryAcquire(self::NAME, 1, $shortLease) !== null);
    }

    /**
     * Forks real processes that all race for the same slots, instead of asserting on a
     * single sequential process. The internal acquire lock ({@see ApcuBulkheadStore})
     * is a spinlock over `apcu_add`; a single-process test cannot tell a correct
     * compare-and-set from one whose success/failure branches are swapped, because
     * `apcu_add`'s side effect (the lock key gets set) happens either way.
     *
     * Two things are required to make this reliable rather than flaky:
     * - A rendezvous barrier (`apcu_inc`/poll) so all workers hit `tryAcquire()` at
     *   the same instant. `pcntl_fork()` itself staggers children by however long
     *   each fork + loop iteration takes; without a barrier that stagger can exceed
     *   the critical section's duration and the race window is simply missed.
     * - A hold time (200ms) comfortably longer than the whole pack's worst-case time
     *   to cycle through the lock, so a worker that legitimately released its slot
     *   isn't double-counted as "another violation" when a later worker reuses it —
     *   that reuse is correct behavior, not a violation, and previously produced
     *   false failures on unmutated code at high worker counts with a short hold.
     */
    public function neverExceedsMaxConcurrentUnderRealForkContention(): void
    {
        if ($this->store === null || !extension_loaded('pcntl')) {
            return;
        }

        $max = 3;
        $workers = 60;
        $resultFile = tempnam(sys_get_temp_dir(), 'bulkhead-fork-stress-');
        Assert::true($resultFile !== false);

        apcu_store('test-barrier:ready', 0);
        apcu_store('test-barrier:go', 0);

        $pids = [];
        for ($i = 0; $i < $workers; ++$i) {
            $pid = pcntl_fork();
            Assert::true($pid !== -1);

            if ($pid === 0) {
                $store = new ApcuBulkheadStore();
                apcu_inc('test-barrier:ready');
                while (apcu_fetch('test-barrier:go') !== 1) {
                    usleep(200);
                }

                $token = $store->tryAcquire('stress', $max, Duration::seconds(5));
                if ($token !== null) {
                    file_put_contents($resultFile, "1\n", \FILE_APPEND | \LOCK_EX);
                    usleep(200_000);
                    $store->release('stress', $token);
                }

                exit(0);
            }

            $pids[] = $pid;
        }

        while (apcu_fetch('test-barrier:ready') < $workers) {
            usleep(200);
        }
        apcu_store('test-barrier:go', 1);

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $acquired = substr_count((string) file_get_contents($resultFile), "1\n");
        unlink($resultFile);

        Assert::true($acquired <= $max);
    }
}
