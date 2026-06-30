<?php

declare(strict_types=1);

use Predis\Client;
use Rasuvaeff\Bulkhead\Redis\PredisScriptRunner;
use Rasuvaeff\Bulkhead\RedisBulkheadStore;
use Rasuvaeff\Bulkhead\SharedBulkhead;
use Rasuvaeff\Duration\Duration;

require dirname(__DIR__) . '/vendor/autoload.php';

$host = getenv('REDIS_HOST');
if ($host === false || $host === '') {
    fwrite(STDERR, "Set REDIS_HOST (and optionally REDIS_PORT) to run this example.\n");

    exit(0);
}

$client = new Client([
    'host' => $host,
    'port' => (int) (getenv('REDIS_PORT') ?: '6379'),
]);

$bulkhead = new SharedBulkhead(
    name: 'legacy-api',
    maxConcurrent: 10,
    store: new RedisBulkheadStore(new PredisScriptRunner($client)),
    lease: Duration::seconds(5),
    maxWait: Duration::millis(200),
);

$result = $bulkhead->call(static fn(): string => 'called downstream');
printf("call returned: %s\n", $result);
printf("available slots: %d\n", $bulkhead->availableSlots());
