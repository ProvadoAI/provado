<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Mquevedob\Provado\Http\HttpRequest;
use Mquevedob\Provado\Http\HttpTransportException;
use Mquevedob\Provado\Http\LaravelHttpClient;
use PHPUnit\Framework\TestCase;

class LaravelHttpClientTest extends TestCase
{
    public function test_it_maps_a_faked_response_without_a_live_call(): void
    {
        $factory = new Factory();
        $factory->fake([
            '*' => Factory::response('{"ok":true}', 200, ['X-Test' => 'yes']),
        ]);

        $client = new LaravelHttpClient($factory);

        $response = $client->send(new HttpRequest('GET', 'https://example.test/data'));

        $this->assertSame(200, $response->status);
        $this->assertSame('{"ok":true}', $response->body);
        $this->assertSame('yes', $response->header('X-Test'));
        $this->assertSame(['ok' => true], $response->json());
    }

    public function test_it_sends_method_query_headers_and_json_body(): void
    {
        $factory = new Factory();
        $factory->fake(['*' => Factory::response('{}', 200)]);

        $client = new LaravelHttpClient($factory);

        $client->send(new HttpRequest(
            method: 'POST',
            uri: 'https://example.test/graphql',
            headers: ['Authorization' => 'Bearer secret'],
            query: ['page' => '2'],
            jsonBody: ['query' => '{ ok }'],
        ));

        $factory->assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'https://example.test/graphql')
                && str_contains($request->url(), 'page=2')
                && $request->hasHeader('Authorization', 'Bearer secret')
                && $request->data() === ['query' => '{ ok }'];
        });
    }

    public function test_an_error_status_is_returned_as_a_response_not_thrown(): void
    {
        $factory = new Factory();
        $factory->fake(['*' => Factory::response('rate limited', 429, ['Retry-After' => '15'])]);

        $client = new LaravelHttpClient($factory);

        $response = $client->send(new HttpRequest('GET', 'https://example.test'));

        $this->assertSame(429, $response->status);
        $this->assertTrue($response->isTooManyRequests());
        $this->assertSame(15, $response->retryAfterSeconds());
    }

    public function test_a_connection_failure_is_mapped_to_a_transport_exception(): void
    {
        $factory = new Factory();
        $factory->fake(fn () => throw new ConnectionException('Connection timed out'));

        $client = new LaravelHttpClient($factory);

        try {
            $client->send(new HttpRequest('GET', 'https://example.test'));
            $this->fail('Expected an HttpTransportException.');
        } catch (HttpTransportException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertSame('https://example.test', $exception->context['uri']);
        }
    }
}
