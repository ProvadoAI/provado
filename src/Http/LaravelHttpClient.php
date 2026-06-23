<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;

/**
 * Default {@see HttpClient} implementation over Laravel's HTTP client.
 *
 * The factory is stored at construction but no request is dispatched until
 * send() is called, so wiring this into the container triggers no outbound
 * calls. In tests a faked Factory (Factory::fake()) is injected, keeping the
 * suite free of live calls.
 */
final readonly class LaravelHttpClient implements HttpClient
{
    public function __construct(
        private Factory $factory,
        private ?float $defaultTimeoutSeconds = null,
        private ?float $defaultConnectTimeoutSeconds = null,
    ) {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $pending = $this->factory->withHeaders($request->headers);

        $pending = $this->applyTimeouts($pending, $request);

        try {
            $response = $pending->send($request->method, $request->uri, $this->options($request));
        } catch (ConnectionException $exception) {
            throw new HttpTransportException(
                message: $exception->getMessage(),
                retryable: true,
                context: [
                    'method' => $request->method,
                    'uri' => $request->uri,
                ],
                previous: $exception,
            );
        }

        return new HttpResponse(
            status: $response->status(),
            body: $response->body(),
            headers: $this->flattenHeaders($response->headers()),
        );
    }

    private function applyTimeouts(PendingRequest $pending, HttpRequest $request): PendingRequest
    {
        $timeout = $request->timeoutSeconds ?? $this->defaultTimeoutSeconds;

        if ($timeout !== null) {
            $pending = $pending->timeout($timeout);
        }

        $connectTimeout = $request->connectTimeoutSeconds ?? $this->defaultConnectTimeoutSeconds;

        if ($connectTimeout !== null) {
            $pending = $pending->connectTimeout($connectTimeout);
        }

        return $pending;
    }

    /**
     * @return array<string, mixed>
     */
    private function options(HttpRequest $request): array
    {
        $options = [];

        if ($request->query !== []) {
            $options['query'] = $request->query;
        }

        if ($request->hasJsonBody()) {
            $options['json'] = $request->jsonBody;
        }

        return $options;
    }

    /**
     * @param array<string, list<string>> $headers
     * @return array<string, string>
     */
    private function flattenHeaders(array $headers): array
    {
        $flattened = [];

        foreach ($headers as $name => $values) {
            $flattened[$name] = implode(', ', $values);
        }

        return $flattened;
    }
}
