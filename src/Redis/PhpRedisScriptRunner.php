<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Redis;

use Rasuvaeff\Bulkhead\BulkheadScriptRunner;

/**
 * phpredis-backed {@see BulkheadScriptRunner}. Requires `ext-redis`.
 *
 * Sends EVALSHA first so the (constant) script body is not re-transmitted and
 * re-hashed by Redis on every acquire/poll tick; falls back to EVAL once per
 * script cache miss (NOSCRIPT), which also loads the script into the cache.
 *
 * @api
 */
final readonly class PhpRedisScriptRunner implements BulkheadScriptRunner
{
    public function __construct(
        private \Redis $client,
    ) {}

    #[\Override]
    public function run(string $script, string $key, array $args): int
    {
        $packed = [$key, ...$args];

        /** @var mixed $reply */
        $reply = $this->client->evalsha(sha1($script), $packed, 1);

        $error = $this->client->getLastError();
        if ($error !== null && str_contains($error, 'NOSCRIPT')) {
            $this->client->clearLastError();
            /** @var mixed $reply */
            $reply = $this->client->eval($script, $packed, 1);
            $error = $this->client->getLastError();
        }

        if ($error !== null) {
            $this->client->clearLastError();

            throw new \RuntimeException(sprintf('Redis script failed: %s', $error));
        }

        return (int) $reply;
    }
}
