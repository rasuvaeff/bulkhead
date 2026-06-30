<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead;

/**
 * @api
 */
final class BulkheadFullException extends \RuntimeException
{
    public function __construct(
        public readonly string $name,
        public readonly int $maxConcurrent,
    ) {
        parent::__construct(
            sprintf('Bulkhead "%s" is full (max %d concurrent)', $name, $maxConcurrent),
        );
    }
}
