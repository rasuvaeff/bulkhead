<?php

declare(strict_types=1);

use Rasuvaeff\Bulkhead\BulkheadFullException;
use Rasuvaeff\Bulkhead\InMemoryBulkheadStore;
use Rasuvaeff\Bulkhead\SharedBulkhead;
use Rasuvaeff\Duration\Duration;

require dirname(__DIR__) . '/vendor/autoload.php';

$store = new InMemoryBulkheadStore();
$bulkhead = new SharedBulkhead(
    name: 'legacy-api',
    maxConcurrent: 2,
    store: $store,
    lease: Duration::seconds(5),
    maxWait: Duration::zero(),
);

$result = $bulkhead->call(static fn(): string => 'called downstream');
printf("call returned: %s\n", $result);
printf("available slots: %d\n", $bulkhead->availableSlots());

// Saturate both slots, then a third call fast-fails instead of piling onto the
// downstream dependency.
$store->tryAcquire('legacy-api', 2, Duration::seconds(5));
$store->tryAcquire('legacy-api', 2, Duration::seconds(5));

try {
    $bulkhead->call(static fn(): string => 'never runs');
} catch (BulkheadFullException $e) {
    printf("rejected: %s\n", $e->getMessage());
}
