<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead;

use Rasuvaeff\Duration\Duration;

/**
 * Single-host cross-process {@see BulkheadStore} backed by APCu.
 *
 * Active slots for $name are kept as one `token => expiresAtMs` array under a
 * single APCu key. Acquire/release read-modify-write that array under a short
 * APCu spinlock (`apcu_add` as create-if-absent), so the check-then-write stays
 * atomic across workers — the same guarantee {@see RedisBulkheadStore} gets
 * from a Lua script. The lock itself carries a TTL, so a worker that dies while
 * holding it does not deadlock the others.
 *
 * Not cross-host: APCu's shared memory segment is local to one machine. Use
 * {@see RedisBulkheadStore} to bound a pool spread across multiple hosts.
 *
 * Known compromise: APCu has no atomic compare-and-delete, so {@see unlock()}
 * cannot verify lock ownership. If a lock holder stalls past LOCK_TTL_SECONDS
 * inside the (microsecond-sized) critical section, its late unlock can delete a
 * successor's lock and briefly break mutual exclusion. The TTL exists to
 * un-deadlock crashed holders; the stall window it opens is accepted as
 * negligible for a critical section this small.
 *
 * @api
 */
final readonly class ApcuBulkheadStore implements BulkheadStore
{
    private const int LOCK_TTL_SECONDS = 1;

    /**
     * @param int $lockMaxAttempts spin budget for the internal lock: acquire/release
     *                             spin up to $lockMaxAttempts × $lockRetryMicros µs
     *                             (default ~100ms) before giving up — tryAcquire then
     *                             reports "full", release leaves the slot to expire
     *                             with its lease
     * @param int $lockRetryMicros pause between lock attempts, microseconds
     */
    public function __construct(
        private string $keyPrefix = 'bulkhead:',
        private int $lockMaxAttempts = 100,
        private int $lockRetryMicros = 1_000,
    ) {
        if ($lockMaxAttempts < 1) {
            throw new \InvalidArgumentException('Lock max attempts must be greater than or equal to 1');
        }
        if ($lockRetryMicros < 1) {
            throw new \InvalidArgumentException('Lock retry micros must be greater than or equal to 1');
        }
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('apcu') && apcu_enabled();
    }

    #[\Override]
    public function tryAcquire(string $name, int $maxConcurrent, Duration $lease): ?string
    {
        if (!$this->lock($name)) {
            return null;
        }

        try {
            $slots = $this->liveSlots($name);
            if (count($slots) >= $maxConcurrent) {
                return null;
            }

            $token = bin2hex(random_bytes(16));
            $slots[$token] = $this->nowMs() + $lease->toMillis();
            if (apcu_store($this->slotsKey($name), $slots) !== true) {
                return null;
            }

            return $token;
        } finally {
            $this->unlock($name);
        }
    }

    #[\Override]
    public function release(string $name, string $token): void
    {
        if (!$this->lock($name)) {
            return;
        }

        try {
            $slots = $this->liveSlots($name);
            unset($slots[$token]);
            apcu_store($this->slotsKey($name), $slots);
        } finally {
            $this->unlock($name);
        }
    }

    #[\Override]
    public function activeCount(string $name): int
    {
        return count($this->liveSlots($name));
    }

    /**
     * @return array<non-empty-string, int>
     */
    private function liveSlots(string $name): array
    {
        $now = $this->nowMs();
        $live = [];

        foreach ($this->rawSlots($name) as $token => $expiresAt) {
            if ($expiresAt > $now) {
                $live[$token] = $expiresAt;
            }
        }

        return $live;
    }

    /**
     * @return array<non-empty-string, int>
     */
    private function rawSlots(string $name): array
    {
        /** @var mixed $stored */
        $stored = apcu_fetch($this->slotsKey($name));

        /** @var array<non-empty-string, int> $slots */
        $slots = is_array($stored) ? $stored : [];

        return $slots;
    }

    private function lock(string $name): bool
    {
        $lockKey = $this->lockKey($name);

        for ($attempt = 0; $attempt < $this->lockMaxAttempts; ++$attempt) {
            if (apcu_add($lockKey, true, self::LOCK_TTL_SECONDS) === true) {
                return true;
            }

            usleep($this->lockRetryMicros);
        }

        return false;
    }

    private function unlock(string $name): void
    {
        apcu_delete($this->lockKey($name));
    }

    private function nowMs(): int
    {
        return (int) (microtime(as_float: true) * 1_000.0);
    }

    private function slotsKey(string $name): string
    {
        return $this->keyPrefix . $name;
    }

    private function lockKey(string $name): string
    {
        return $this->keyPrefix . 'lock:' . $name;
    }
}
