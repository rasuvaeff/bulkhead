<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead;

use Rasuvaeff\Duration\Duration;

/**
 * @api
 */
final class BulkheadFullException extends \RuntimeException
{
    /**
     * @param ?Duration $waited how long the call waited for a slot before giving up;
     *                          null when the exception was not thrown by this package
     * @param ?Duration $lease  the bulkhead's slot lease, the basis of {@see retryAfter()}
     */
    public function __construct(
        public readonly string $name,
        public readonly int $maxConcurrent,
        public readonly ?Duration $waited = null,
        public readonly ?Duration $lease = null,
    ) {
        parent::__construct(
            sprintf('Bulkhead "%s" is full (max %d concurrent)', $name, $maxConcurrent),
        );
    }

    /**
     * Upper bound on when a slot frees up: every slot held right now ends
     * within one lease — released by its holder or, at the latest, reclaimed
     * when the lease expires. Usually a slot frees much sooner, and other
     * waiters may take it first, so treat this as a `Retry-After` ceiling,
     * not a promise.
     *
     * {@see InMemoryBulkheadStore} ignores leases; there the value is still
     * the configured lease, a heuristic rather than a bound. Null when the
     * exception was constructed without a lease (not by this package).
     *
     * Relative, unlike circuit-breaker's `CircuitOpenException::$retryAfter`,
     * which is an absolute instant: a bulkhead has no clock to anchor one to.
     */
    public function retryAfter(): ?Duration
    {
        return $this->lease;
    }
}
