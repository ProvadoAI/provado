<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Http;

use RuntimeException;
use Throwable;

/**
 * Raised when an HTTP request cannot complete at the transport layer — a
 * connection failure or timeout, i.e. no HTTP response was ever received.
 *
 * HTTP error statuses (4xx/5xx) are NOT transport failures; those come back as
 * an {@see HttpResponse}. Transport failures default to retryable because a
 * timeout or dropped connection is typically transient.
 */
final class HttpTransportException extends RuntimeException
{
    /**
     * @var array<string, mixed>
     */
    public readonly array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        public readonly bool $retryable = true,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);

        $this->context = $context;
    }
}
