<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Tests;

use Predis\ClientInterface;
use Predis\Command\RawCommand;
use Predis\Response\ServerException;
use Rasuvaeff\Bulkhead\Redis\PredisScriptRunner;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(PredisScriptRunner::class)]
final class PredisScriptRunnerTest
{
    public function executesScriptViaEvalshaFirst(): void
    {
        $client = $this->client(reply: 1);
        $runner = new PredisScriptRunner($client);

        $result = $runner->run('return 1', 'bulkhead:svc', [2, 5000]);

        Assert::same($result, 1);
        Assert::same($this->executedCommands($client), [
            ['EVALSHA', [sha1('return 1'), 1, 'bulkhead:svc', 2, 5000]],
        ]);
    }

    public function fallsBackToEvalWhenScriptNotCached(): void
    {
        $client = $this->client(reply: 1, evalshaError: 'NOSCRIPT No matching script. Please use EVAL.');
        $runner = new PredisScriptRunner($client);

        $result = $runner->run('return 1', 'bulkhead:svc', [2]);

        Assert::same($result, 1);
        Assert::same($this->executedCommands($client), [
            ['EVALSHA', [sha1('return 1'), 1, 'bulkhead:svc', 2]],
            ['EVAL', ['return 1', 1, 'bulkhead:svc', 2]],
        ]);
    }

    public function rethrowsNonNoscriptServerErrors(): void
    {
        $client = $this->client(reply: 1, evalshaError: 'ERR Error compiling script');
        $runner = new PredisScriptRunner($client);

        Expect::exception(ServerException::class)->withMessageContaining('Error compiling script');

        $runner->run('return 1', 'bulkhead:svc', []);
    }

    public function castsScriptReplyToInt(): void
    {
        $client = $this->client(reply: '3');
        $runner = new PredisScriptRunner($client);

        Assert::same($runner->run('return 3', 'k', []), 3);
    }

    public function runManySendsEveryKeyWithTheirCount(): void
    {
        $client = $this->client(reply: [1, 2]);
        $runner = new PredisScriptRunner($client);

        $result = $runner->runMany('return {1, 2}', ['bulkhead:a', 'bulkhead:b'], [5000]);

        Assert::same($result, [1, 2]);
        Assert::same($this->executedCommands($client), [
            ['EVALSHA', [sha1('return {1, 2}'), 2, 'bulkhead:a', 'bulkhead:b', 5000]],
        ]);
    }

    public function runManyFallsBackToEvalWhenScriptNotCached(): void
    {
        $client = $this->client(reply: [0, 1], evalshaError: 'NOSCRIPT No matching script. Please use EVAL.');
        $runner = new PredisScriptRunner($client);

        $result = $runner->runMany('return {0, 1}', ['k1', 'k2'], []);

        Assert::same($result, [0, 1]);
        Assert::same($this->executedCommands($client), [
            ['EVALSHA', [sha1('return {0, 1}'), 2, 'k1', 'k2']],
            ['EVAL', ['return {0, 1}', 2, 'k1', 'k2']],
        ]);
    }

    public function runManyCastsEachReplyToIntAndDropsKeys(): void
    {
        $client = $this->client(reply: ['x' => '3', 'y' => 4, 'z' => null]);
        $runner = new PredisScriptRunner($client);

        Assert::same($runner->runMany('return {}', ['k'], []), [3, 4, 0]);
    }

    public function runManyTreatsANonArrayReplyAsEmpty(): void
    {
        $client = $this->client(reply: 3);
        $runner = new PredisScriptRunner($client);

        Assert::same($runner->runMany('return 3', ['k'], []), []);
    }

    private function client(mixed $reply, ?string $evalshaError = null): ClientInterface
    {
        $client = Understudy::for(ClientInterface::class);
        when(fn() => $client->createCommand(Arg::any(), Arg::any()))
            ->answers(static fn(Invocation $call): RawCommand => new RawCommand((string) $call->args[0], (array) $call->args[1]));
        $evalsha = fn() => $client->executeCommand(Arg::which('getId', 'EVALSHA'));
        $eval = fn() => $client->executeCommand(Arg::which('getId', 'EVAL'));

        if ($evalshaError === null) {
            when($evalsha)->returns($reply);
        } else {
            when($evalsha)->throws(new ServerException($evalshaError));
        }
        when($eval)->returns($reply);

        return $client;
    }

    /** @return list<array{string, array}> */
    private function executedCommands(ClientInterface $client): array
    {
        return array_map(
            static fn(Invocation $call): array => [(string) $call->args[0]->getId(), $call->args[0]->getArguments()],
            Understudy::calls(fn() => $client->executeCommand(Arg::any())),
        );
    }
}
