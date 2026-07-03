<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead;

use Rasuvaeff\Duration\Duration;

/**
 * Cross-process {@see BulkheadStore} backed by a Redis sorted set.
 *
 * Each active slot is a member of `ZSET <prefix><name>` scored with its lease
 * expiry (ms). Acquire prunes expired members, checks the cardinality against
 * the limit, and adds a new member — all in one Lua script, so the check and the
 * add are atomic (no check-then-incr race across workers). A dead worker's slot
 * is reclaimed automatically once its lease score passes.
 *
 * Assumes Redis 5+ (effects replication), so `redis.call('TIME')` inside the
 * script needs no `replicate_commands()`.
 *
 * @api
 */
final readonly class RedisBulkheadStore implements BulkheadStore
{
    private const string ACQUIRE = <<<'LUA'
        local now = redis.call('TIME')
        local now_ms = tonumber(now[1]) * 1000 + math.floor(tonumber(now[2]) / 1000)
        redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', now_ms)
        if redis.call('ZCARD', KEYS[1]) < tonumber(ARGV[1]) then
            redis.call('ZADD', KEYS[1], now_ms + tonumber(ARGV[2]), ARGV[3])
            -- Never shrink the key TTL: a shorter lease on the same name must not
            -- expire the whole set while longer-lease members are still live.
            if tonumber(redis.call('PTTL', KEYS[1])) < tonumber(ARGV[2]) then
                redis.call('PEXPIRE', KEYS[1], tonumber(ARGV[2]))
            end
            return 1
        end
        return 0
        LUA;

    private const string RELEASE = <<<'LUA'
        return redis.call('ZREM', KEYS[1], ARGV[1])
        LUA;

    private const string COUNT = <<<'LUA'
        local now = redis.call('TIME')
        local now_ms = tonumber(now[1]) * 1000 + math.floor(tonumber(now[2]) / 1000)
        redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', now_ms)
        return redis.call('ZCARD', KEYS[1])
        LUA;

    public function __construct(
        private BulkheadScriptRunner $runner,
        private string $keyPrefix = 'bulkhead:',
    ) {}

    #[\Override]
    public function tryAcquire(string $name, int $maxConcurrent, Duration $lease): ?string
    {
        $token = bin2hex(random_bytes(16));
        $acquired = $this->runner->run(
            self::ACQUIRE,
            $this->key($name),
            [$maxConcurrent, $lease->toMillis(), $token],
        );

        return $acquired === 1 ? $token : null;
    }

    #[\Override]
    public function release(string $name, string $token): void
    {
        $this->runner->run(self::RELEASE, $this->key($name), [$token]);
    }

    #[\Override]
    public function activeCount(string $name): int
    {
        return max(0, $this->runner->run(self::COUNT, $this->key($name), []));
    }

    private function key(string $name): string
    {
        return $this->keyPrefix . $name;
    }
}
