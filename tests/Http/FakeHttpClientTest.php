<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests\Http;

use Mquevedob\Provado\Http\FakeHttpClient;
use Mquevedob\Provado\Http\HttpRequest;
use Mquevedob\Provado\Http\HttpResponse;
use Mquevedob\Provado\Http\HttpTransportException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FakeHttpClientTest extends TestCase
{
    public function test_it_returns_queued_responses_in_order_and_records_requests(): void
    {
        $client = new FakeHttpClient();
        $client->respondWith(new HttpResponse(200, 'first'))
            ->respondWith(new HttpResponse(201, 'second'));

        $requestA = new HttpRequest('GET', 'https://example.test/a');
        $requestB = new HttpRequest('POST', 'https://example.test/b');

        $this->assertSame('first', $client->send($requestA)->body);
        $this->assertSame('second', $client->send($requestB)->body);

        $this->assertSame([$requestA, $requestB], $client->sentRequests());
        $this->assertSame($requestB, $client->lastRequest());
    }

    public function test_it_throws_a_queued_failure(): void
    {
        $client = new FakeHttpClient();
        $client->failWith(new HttpTransportException('timeout'));

        $this->expectException(HttpTransportException::class);

        $client->send(new HttpRequest('GET', 'https://example.test'));
    }

    public function test_it_throws_when_no_response_is_queued(): void
    {
        $client = new FakeHttpClient();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FakeHttpClient has no queued response for the request.');

        $client->send(new HttpRequest('GET', 'https://example.test'));
    }

    public function test_last_request_is_null_before_any_send(): void
    {
        $this->assertNull((new FakeHttpClient())->lastRequest());
    }
}
