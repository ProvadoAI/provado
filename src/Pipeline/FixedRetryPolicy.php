<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Pipeline;

use InvalidArgumentException;

/**
 * Retries a source fetch with retryable errors up to a fixed number of total attempts.
 */
final readonly class FixedRetryPolicy implements RetryPolicy
{
    public function __construct(private int $maxAttempts)
    {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('FixedRetryPolicy max attempts must be at least 1.');
        }
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }
}
