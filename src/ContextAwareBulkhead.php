<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead;

use Rasuvaeff\Context\Context;

/**
 * A bulkhead that can wait for a slot under a `rasuvaeff/context` budget.
 *
 * @api
 */
interface ContextAwareBulkhead extends Bulkhead
{
    /**
     * The same bulkhead (same name, limit and slots), but the wait for a slot
     * never outlasts the context: it is capped by the remaining budget and
     * ends with the context's exception — `ContextExpiredException` or
     * `ContextCancelledException`, never `BulkheadFullException` — when the
     * context terminates first.
     */
    public function withContext(Context $context): Bulkhead;
}
