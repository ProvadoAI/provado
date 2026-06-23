<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Http;

use Mquevedob\Provado\Sources\SourceFetchError;

/**
 * Maps HTTP failures into the canonical {@see SourceFetchError} so the existing
 * RetryPolicy can drive retries off the `retryable` flag.
 *
 * Classification is provider-agnostic — the source name is passed in, and no
 * vendor response shapes are inspected:
 *   - transport failures (timeout/connection) → retryable
 *   - 429 Too Many Requests                    → retryable (honors Retry-After)
 *   - 5xx server errors                        → retryable
 *   - 401/403                                  → not retryable (auth)
 *   - other 4xx                                → not retryable (config/client)
 */
final readonly class HttpSourceErrorFactory
{
    /**
     * A status is worth retrying when it is transient: rate limiting (429) or
     * any server-side (5xx) failure.
     */
    public static function isRetryableStatus(int $status): bool
    {
        return $status === 429 || ($status >= 500 && $status < 600);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function fromResponse(
        string $sourceName,
        HttpRequest $request,
        HttpResponse $response,
        array $context = [],
    ): SourceFetchError {
        [$code, $message] = $this->classifyStatus($response->status);

        $errorContext = [
            'method' => $request->method,
            'uri' => $request->uri,
            'status' => $response->status,
            ...$context,
        ];

        if ($response->isTooManyRequests()) {
            $retryAfter = $response->retryAfterSeconds();

            if ($retryAfter !== null) {
                $errorContext['retry_after'] = $retryAfter;
            }
        }

        return new SourceFetchError(
            sourceName: $sourceName,
            message: $message,
            code: $code,
            retryable: self::isRetryableStatus($response->status),
            context: $errorContext,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public function fromTransportException(
        string $sourceName,
        HttpRequest $request,
        HttpTransportException $exception,
        array $context = [],
    ): SourceFetchError {
        return new SourceFetchError(
            sourceName: $sourceName,
            message: 'HTTP transport failure while contacting the source.',
            code: 'transport_error',
            retryable: $exception->retryable,
            context: [
                'method' => $request->method,
                'uri' => $request->uri,
                'reason' => $exception->getMessage(),
                ...$context,
            ],
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function classifyStatus(int $status): array
    {
        if ($status === 429) {
            return ['rate_limited', 'HTTP request was rate limited by the source.'];
        }

        if ($status >= 500 && $status < 600) {
            return ['server_error', 'Source returned a server error.'];
        }

        if ($status === 401 || $status === 403) {
            return ['auth_error', 'Source rejected the request credentials.'];
        }

        if ($status >= 400 && $status < 500) {
            return ['client_error', 'Source rejected the request.'];
        }

        return ['unexpected_status', 'Source returned an unexpected HTTP status.'];
    }
}
