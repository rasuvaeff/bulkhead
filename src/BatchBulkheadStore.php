<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead;

/**
 * A {@see BulkheadStore} that can snapshot the active load of many bulkheads at once.
 *
 * Meant for least-loaded selection among hundreds of candidates, where one
 * `activeCount()` per name would cost one store round trip each.
 *
 * @api
 */
interface BatchBulkheadStore extends BulkheadStore
{
    /**
     * Active (non-expired) slot counts for every name in $names, in one store
     * round trip where the backend allows it.
     *
     * Read-only with respect to slots: never acquires or releases one. Like
     * {@see BulkheadStore::activeCount()}, backends that reclaim expired leases
     * lazily may prune them as a side effect.
     *
     * A name with no active slots maps to `0`. Duplicate names are collapsed:
     * each name appears once in the result, keyed by name, in first-seen order.
     * PHP array semantics apply to the keys: a numeric name such as `'42'`
     * comes back as the int key `42` — cast it with `(string)` before passing
     * it to a `string $name` parameter.
     *
     * @param list<non-empty-string> $names
     *
     * @return array<int|non-empty-string, int<0, max>>
     */
    public function activeCounts(array $names): array;
}
