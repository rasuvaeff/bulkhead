<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Redis;

use Predis\ClientInterface;
use Predis\Response\ServerException;
use Rasuvaeff\Bulkhead\BulkheadMultiKeyScriptRunner;

/**
 * predis-backed {@see BulkheadMultiKeyScriptRunner}.
 *
 * Sends EVALSHA first so the (constant) script body is not re-transmitted and
 * re-hashed by Redis on every acquire/poll tick; falls back to EVAL once per
 * script cache miss (NOSCRIPT), which also loads the script into the cache.
 *
 * @api
 */
final readonly class PredisScriptRunner implements BulkheadMultiKeyScriptRunner
{
    public function __construct(
        private ClientInterface $client,
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
        // createCommand/executeCommand are real ClientInterface methods; the
        // magic eval()/evalsha() @method annotations are not resolvable by
        // psalm across every supported predis release.
        try {
            /** @var mixed $reply */
            $reply = $this->client->executeCommand(
                $this->client->createCommand('EVALSHA', [sha1($script), count($keys), ...$keys, ...$args]),
            );
        } catch (ServerException $e) {
            if ($e->getErrorType() !== 'NOSCRIPT') {
                throw $e;
            }

            /** @var mixed $reply */
            $reply = $this->client->executeCommand(
                $this->client->createCommand('EVAL', [$script, count($keys), ...$keys, ...$args]),
            );
        }

        return $reply;
    }
}
