<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Http;

use InvalidArgumentException;
use JsonException;

/**
 * Immutable HTTP response returned by an {@see HttpClient}.
 *
 * Any HTTP status (including 4xx/5xx) is represented as a response — transport
 * failures are signalled separately via {@see HttpTransportException}. Retry
 * classification lives in {@see HttpSourceErrorFactory}, not here.
 */
final readonly class HttpResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        public string $body = '',
        public array $headers = [],
    ) {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('HTTP response status must be a valid status code.');
        }

        foreach (array_keys($headers) as $headerName) {
            if (! is_string($headerName) || trim($headerName) === '') {
                throw new InvalidArgumentException('HTTP response header names cannot be empty.');
            }
        }
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function isClientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    public function isServerError(): bool
    {
        return $this->status >= 500 && $this->status < 600;
    }

    public function isTooManyRequests(): bool
    {
        return $this->status === 429;
    }

    /**
     * Case-insensitive header lookup.
     */
    public function header(string $name): ?string
    {
        $target = strtolower($name);

        foreach ($this->headers as $headerName => $value) {
            if (strtolower((string) $headerName) === $target) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Decode the body as JSON (associative). Returns null for an empty body.
     *
     * @throws JsonException when the body is not valid JSON.
     */
    public function json(): mixed
    {
        if (trim($this->body) === '') {
            return null;
        }

        return json_decode($this->body, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Resolve the Retry-After header to a whole number of seconds.
     *
     * Supports both the delta-seconds form (e.g. "120") and the HTTP-date form,
     * returning null when the header is absent or unparseable.
     */
    public function retryAfterSeconds(): ?int
    {
        $value = $this->header('Retry-After');

        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        return max(0, $timestamp - time());
    }
}
