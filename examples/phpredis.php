<?php

declare(strict_types=1);

use Rasuvaeff\Bulkhead\Redis\PhpRedisScriptRunner;
use Rasuvaeff\Bulkhead\RedisBulkheadStore;
use Rasuvaeff\Bulkhead\SharedBulkhead;
use Rasuvaeff\Duration\Duration;

require dirname(__DIR__) . '/vendor/autoload.php';

if (!extension_loaded('redis')) {
    fwrite(STDERR, "Install ext-redis to run this example (predis users: see redis.php).\n");

    exit(0);
}

$host = getenv('REDIS_HOST');
if ($host === false || $host === '') {
    fwrite(STDERR, "Set REDIS_HOST (and optionally REDIS_PORT) to run this example.\n");

    exit(0);
}

$client = new Redis();
$client->connect($host, (int) (getenv('REDIS_PORT') ?: '6379'));

$bulkhead = new SharedBulkhead(
    name: 'legacy-api',
    maxConcurrent: 10,
    store: new RedisBulkheadStore(new PhpRedisScriptRunner($client)),
    lease: Duration::seconds(5),
    maxWait: Duration::millis(200),
    pollJitter: 0.5,
    onAccepted: static fn(string $name, Duration $waited) => printf("accepted after %dms wait\n", $waited->toMillis()),
);

$result = $bulkhead->call(static fn(): string => 'called downstream');
printf("call returned: %s\n", $result);
printf("available slots: %d\n", $bulkhead->availableSlots());
