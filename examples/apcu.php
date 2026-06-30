<?php

declare(strict_types=1);

use Rasuvaeff\Bulkhead\ApcuBulkheadStore;
use Rasuvaeff\Bulkhead\SharedBulkhead;
use Rasuvaeff\Duration\Duration;

require dirname(__DIR__) . '/vendor/autoload.php';

if (!ApcuBulkheadStore::isAvailable()) {
    fwrite(STDERR, "Enable ext-apcu (and apc.enable_cli=1 for CLI) to run this example.\n");

    exit(0);
}

$bulkhead = new SharedBulkhead(
    name: 'legacy-api',
    maxConcurrent: 10,
    store: new ApcuBulkheadStore(),
    lease: Duration::seconds(5),
    maxWait: Duration::millis(200),
);

$result = $bulkhead->call(static fn(): string => 'called downstream');
printf("call returned: %s\n", $result);
printf("available slots: %d\n", $bulkhead->availableSlots());
