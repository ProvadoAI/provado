<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Http;

/**
 * Provider-agnostic HTTP seam that real source clients sit on.
 *
 * Implementations perform no outbound I/O until send() is invoked. No vendor
 * response shapes leak through this contract: callers receive a canonical
 * {@see HttpResponse} and map provider semantics above the adapter seam.
 */
interface HttpClient
{
    /**
     * Perform the request and return the response for any HTTP status.
     *
     * 4xx/5xx are returned as an {@see HttpResponse}; only transport-level
     * failures (connection drop, timeout) throw.
     *
     * @throws HttpTransportException when no HTTP response could be obtained.
     */
    public function send(HttpRequest $request): HttpResponse;
}
