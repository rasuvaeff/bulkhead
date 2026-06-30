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
 * @api
 */
final readonly class ApcuBulkheadStore implements BulkheadStore
{
    private const int LOCK_TTL_SECONDS = 1;
    private const int LOCK_MAX_ATTEMPTS = 1_000;
    private const int LOCK_RETRY_MICROS = 1_000;

    public function __construct(
        private string $keyPrefix = 'bulkhead:',
    ) {}

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
            apcu_store($this->slotsKey($name), $slots);

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

        /** @var array<non-empty-string, int> */
        return is_array($stored) ? $stored : [];
    }

    private function lock(string $name): bool
    {
        $lockKey = $this->lockKey($name);

        for ($attempt = 0; $attempt < self::LOCK_MAX_ATTEMPTS; ++$attempt) {
            if (apcu_add($lockKey, true, self::LOCK_TTL_SECONDS) === true) {
                return true;
            }

            usleep(self::LOCK_RETRY_MICROS);
        }

        return false;
    }

    private function unlock(string $name): void
    {
        apcu_delete($this->lockKey($name));
    }

    private function nowMs(): int
    {
        return (int) (microtime(true) * 1_000.0);
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
