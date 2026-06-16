<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Pipeline;

/**
 * Decides how many times a source fetch may be attempted when it returns
 * retryable errors. Attempts are counted inclusively (1 = no retry).
 */
interface RetryPolicy
{
    public function maxAttempts(): int;
}
