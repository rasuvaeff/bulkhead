<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Redis;

use Rasuvaeff\Bulkhead\BulkheadMultiKeyScriptRunner;

/**
 * phpredis-backed {@see BulkheadMultiKeyScriptRunner}. Requires `ext-redis`.
 *
 * Sends EVALSHA first so the (constant) script body is not re-transmitted and
 * re-hashed by Redis on every acquire/poll tick; falls back to EVAL once per
 * script cache miss (NOSCRIPT), which also loads the script into the cache.
 *
 * @api
 */
final readonly class PhpRedisScriptRunner implements BulkheadMultiKeyScriptRunner
{
    public function __construct(
        private \Redis $client,
    ) {}

    #[\Override]
    public function run(string $script, string $key, array $args): int
    {
        return (int) $this->evaluate($script, [$key], $args);
    }

    #[\Override]
    public function runMany(string $script, array $keys, array $args): array
    {
        /** @var mixed $reply */
        $reply = $this->evaluate($script, $keys, $args);
        $counts = [];

        /** @var mixed $count */
        foreach (is_array($reply) ? $reply : [] as $count) {
            $counts[] = (int) $count;
        }

        return $counts;
    }

    /**
     * @param list<string>     $keys
     * @param list<int|string> $args
     */
    private function evaluate(string $script, array $keys, array $args): mixed
    {
        $packed = [...$keys, ...$args];
        $numKeys = count($keys);

        /** @var mixed $reply */
        $reply = $this->client->evalsha(sha1($script), $packed, $numKeys);

        $error = $this->client->getLastError();
        if ($error !== null && str_contains($error, 'NOSCRIPT')) {
            $this->client->clearLastError();
            /** @var mixed $reply */
            $reply = $this->client->eval($script, $packed, $numKeys);
            $error = $this->client->getLastError();
        }

        if ($error !== null) {
            $this->client->clearLastError();

            throw new \RuntimeException(sprintf('Redis script failed: %s', $error));
        }

        return $reply;
    }
}
