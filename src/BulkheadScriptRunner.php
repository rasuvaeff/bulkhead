<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead;

/**
 * Typed seam over a Redis server-side script call.
 *
 * Isolates the untyped Redis-client boundary (predis magic `__call`, phpredis)
 * from {@see RedisBulkheadStore}, which stays fully typed. Implement this to
 * back the store with a different client (e.g. phpredis).
 *
 * @api
 */
interface BulkheadScriptRunner
{
    /**
     * Evaluate a Lua script addressing a single key and return its integer reply.
     *
     * @param list<int|string> $args
     */
    public function run(string $script, string $key, array $args): int;
}
