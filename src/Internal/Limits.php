<?php

declare(strict_types=1);

namespace Rasuvaeff\Bulkhead\Internal;

/**
 * Shared validation of a bulkhead's identity: the name and the limit.
 *
 * @internal
 */
final readonly class Limits
{
    private const string NAME_PATTERN = '/^[A-Za-z0-9_.:-]+\z/';

    /**
     * @return non-empty-string
     */
    public static function name(string $name): string
    {
        if ($name === '' || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid bulkhead name "%s"', $name));
        }

        return $name;
    }

    /**
     * @return positive-int
     */
    public static function maxConcurrent(int $maxConcurrent): int
    {
        if ($maxConcurrent < 1) {
            throw new \InvalidArgumentException('Max concurrent must be greater than or equal to 1');
        }

        return $maxConcurrent;
    }
}
