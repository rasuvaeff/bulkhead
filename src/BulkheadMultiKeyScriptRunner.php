<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead;

/**
 * A {@see BulkheadScriptRunner} that can also address several keys in one script call.
 *
 * {@see RedisBulkheadStore::activeCounts()} needs it for a single round trip;
 * with a runner that only implements {@see BulkheadScriptRunner} the store
 * falls back to one {@see BulkheadScriptRunner::run()} call per name.
 *
 * @api
 */
interface BulkheadMultiKeyScriptRunner extends BulkheadScriptRunner
{
    /**
     * Evaluate a Lua script addressing $keys and return its integer-array reply,
     * one element per key in the same order.
     *
     * @param list<string>     $keys
     * @param list<int|string> $args
     *
     * @return list<int>
     */
    public function runMany(string $script, array $keys, array $args): array;
}
