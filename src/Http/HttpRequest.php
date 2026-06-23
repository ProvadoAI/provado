<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Http;

use InvalidArgumentException;

/**
 * Immutable description of an outbound HTTP request.
 *
 * Constructing one performs no I/O; an {@see HttpClient} turns it into a real
 * call only when send() is invoked. Query parameters are kept separate from the
 * URI so error context can surface the endpoint without leaking secrets that may
 * ride along in the query string.
 */
final readonly class HttpRequest
{
    public string $method;

    public string $uri;

    /**
     * @var array<string, string>
     */
    public array $headers;

    /**
     * @var array<string, scalar>
     */
    public array $query;

    /**
     * @var array<array-key, mixed>|null
     */
    public ?array $jsonBody;

    public ?float $timeoutSeconds;

    public ?float $connectTimeoutSeconds;

    /**
     * @param array<string, string> $headers
     * @param array<string, scalar> $query
     * @param array<array-key, mixed>|null $jsonBody
     */
    public function __construct(
        string $method,
        string $uri,
        array $headers = [],
        array $query = [],
        ?array $jsonBody = null,
        ?float $timeoutSeconds = null,
        ?float $connectTimeoutSeconds = null,
    ) {
        $normalizedMethod = strtoupper(trim($method));

        if ($normalizedMethod === '') {
            throw new InvalidArgumentException('HTTP request method cannot be empty.');
        }

        if (trim($uri) === '') {
            throw new InvalidArgumentException('HTTP request URI cannot be empty.');
        }

        foreach (array_keys($headers) as $headerName) {
            if (! is_string($headerName) || trim($headerName) === '') {
                throw new InvalidArgumentException('HTTP request header names cannot be empty.');
            }
        }

        foreach (array_keys($query) as $queryName) {
            if (! is_string($queryName) || trim($queryName) === '') {
                throw new InvalidArgumentException('HTTP request query parameter names cannot be empty.');
            }
        }

        if ($timeoutSeconds !== null && $timeoutSeconds <= 0) {
            throw new InvalidArgumentException('HTTP request timeout must be greater than zero.');
        }

        if ($connectTimeoutSeconds !== null && $connectTimeoutSeconds <= 0) {
            throw new InvalidArgumentException('HTTP request connect timeout must be greater than zero.');
        }

        $this->method = $normalizedMethod;
        $this->uri = trim($uri);
        $this->headers = $headers;
        $this->query = $query;
        $this->jsonBody = $jsonBody;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->connectTimeoutSeconds = $connectTimeoutSeconds;
    }

    /**
     * Return a copy of this request with an additional (or replaced) header.
     *
     * Provider adapters use this to inject their auth header without coupling
     * the seam to any vendor's credential scheme.
     */
    public function withHeader(string $name, string $value): self
    {
        return new self(
            $this->method,
            $this->uri,
            [...$this->headers, $name => $value],
            $this->query,
            $this->jsonBody,
            $this->timeoutSeconds,
            $this->connectTimeoutSeconds,
        );
    }

    public function hasJsonBody(): bool
    {
        return $this->jsonBody !== null;
    }
}
