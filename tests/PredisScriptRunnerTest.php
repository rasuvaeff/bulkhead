<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Tests;

use Predis\ClientInterface;
use Predis\Response\ServerException;
use Rasuvaeff\Bulkhead\Redis\PredisScriptRunner;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(PredisScriptRunner::class)]
final class PredisScriptRunnerTest
{
    public function executesScriptViaEvalshaFirst(): void
    {
        $client = $this->fakeClient(reply: 1);
        $runner = new PredisScriptRunner($client);

        $result = $runner->run('return 1', 'bulkhead:svc', [2, 5000]);

        Assert::same($result, 1);
        Assert::same(count($client->calls), 1);
        Assert::same($client->calls[0], ['evalsha', [sha1('return 1'), 1, 'bulkhead:svc', 2, 5000]]);
    }

    public function fallsBackToEvalWhenScriptNotCached(): void
    {
        $client = $this->fakeClient(reply: 1, evalshaError: 'NOSCRIPT No matching script. Please use EVAL.');
        $runner = new PredisScriptRunner($client);

        $result = $runner->run('return 1', 'bulkhead:svc', [2]);

        Assert::same($result, 1);
        Assert::same(count($client->calls), 2);
        Assert::same($client->calls[0][0], 'evalsha');
        Assert::same($client->calls[1], ['eval', ['return 1', 1, 'bulkhead:svc', 2]]);
    }

    public function rethrowsNonNoscriptServerErrors(): void
    {
        $client = $this->fakeClient(reply: 1, evalshaError: 'ERR Error compiling script');
        $runner = new PredisScriptRunner($client);

        Expect::exception(ServerException::class)->withMessageContaining('Error compiling script');

        $runner->run('return 1', 'bulkhead:svc', []);
    }

    public function castsScriptReplyToInt(): void
    {
        $client = $this->fakeClient(reply: '3');
        $runner = new PredisScriptRunner($client);

        Assert::same($runner->run('return 3', 'k', []), 3);
    }

    /**
     * @return ClientInterface&object{calls: list<array{string, array}>}
     */
    private function fakeClient(mixed $reply, ?string $evalshaError = null): object
    {
        return new class ($reply, $evalshaError) implements ClientInterface {
            /** @var list<array{string, array}> */
            public array $calls = [];

            public function __construct(
                private readonly mixed $reply,
                private readonly ?string $evalshaError,
            ) {}

            #[\Override]
            public function __call($method, $arguments): mixed
            {
                $this->calls[] = [(string) $method, (array) $arguments];

                if ($method === 'evalsha' && $this->evalshaError !== null) {
                    throw new ServerException($this->evalshaError);
                }

                return $this->reply;
            }

            #[\Override]
            public function getCommandFactory(): never
            {
                throw new \LogicException('Not used');
            }

            #[\Override]
            public function getOptions(): never
            {
                throw new \LogicException('Not used');
            }

            #[\Override]
            public function connect(): never
            {
                throw new \LogicException('Not used');
            }

            #[\Override]
            public function disconnect(): never
            {
                throw new \LogicException('Not used');
            }

            #[\Override]
            public function getConnection(): never
            {
                throw new \LogicException('Not used');
            }

            #[\Override]
            public function createCommand($method, $arguments = []): never
            {
                throw new \LogicException('Not used');
            }

            #[\Override]
            public function executeCommand($command): never
            {
                throw new \LogicException('Not used');
            }
        };
    }
}
