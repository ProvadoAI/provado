<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Http;

use RuntimeException;
use Throwable;

/**
 * In-memory {@see HttpClient} test double. Performs no live calls.
 *
 * Queue canned responses (or transport failures) with respondWith()/failWith();
 * each send() consumes the next queued outcome in order and records the request
 * for later assertions.
 */
final class FakeHttpClient implements HttpClient
{
    /**
     * @var list<HttpResponse|Throwable>
     */
    private array $queue = [];

    /**
     * @var list<HttpRequest>
     */
    private array $sentRequests = [];

    public function respondWith(HttpResponse $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    public function failWith(Throwable $exception): self
    {
        $this->queue[] = $exception;

        return $this;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->sentRequests[] = $request;

        if ($this->queue === []) {
            throw new RuntimeException('FakeHttpClient has no queued response for the request.');
        }

        $next = array_shift($this->queue);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    /**
     * @return list<HttpRequest>
     */
    public function sentRequests(): array
    {
        return $this->sentRequests;
    }

    public function lastRequest(): ?HttpRequest
    {
        if ($this->sentRequests === []) {
            return null;
        }

        return $this->sentRequests[array_key_last($this->sentRequests)];
    }
}
