<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Redis;

use Predis\ClientInterface;
use Rasuvaeff\Bulkhead\BulkheadScriptRunner;

/**
 * predis-backed {@see BulkheadScriptRunner}.
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
        /** @var mixed $reply */
        $reply = $this->client->eval($script, 1, $key, ...$args);

        return (int) $reply;
    }
}
