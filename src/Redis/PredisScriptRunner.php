<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Redis;

use Predis\ClientInterface;
use Predis\Response\ServerException;
use Rasuvaeff\Bulkhead\BulkheadScriptRunner;

/**
 * predis-backed {@see BulkheadScriptRunner}.
 *
 * Sends EVALSHA first so the (constant) script body is not re-transmitted and
 * re-hashed by Redis on every acquire/poll tick; falls back to EVAL once per
 * script cache miss (NOSCRIPT), which also loads the script into the cache.
 *
 * @api
 */
final readonly class PredisScriptRunner implements BulkheadScriptRunner
{
    public function __construct(
        private ClientInterface $client,
    ) {}

    #[\Override]
    public function run(string $script, string $key, array $args): int
    {
        // createCommand/executeCommand are real ClientInterface methods; the
        // magic eval()/evalsha() @method annotations are not resolvable by
        // psalm across every supported predis release.
        try {
            /** @var mixed $reply */
            $reply = $this->client->executeCommand(
                $this->client->createCommand('EVALSHA', [sha1($script), 1, $key, ...$args]),
            );
        } catch (ServerException $e) {
            if ($e->getErrorType() !== 'NOSCRIPT') {
                throw $e;
            }

            /** @var mixed $reply */
            $reply = $this->client->executeCommand(
                $this->client->createCommand('EVAL', [$script, 1, $key, ...$args]),
            );
        }

        return (int) $reply;
    }
}
