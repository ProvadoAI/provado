<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Pipeline;

/**
 * Default policy: a source fetch is attempted once, never retried.
 */
final readonly class NoRetryPolicy implements RetryPolicy
{
    public function maxAttempts(): int
    {
        return 1;
    }
}
