<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests\Http;

use InvalidArgumentException;
use Mquevedob\Provado\Http\HttpRequest;
use PHPUnit\Framework\TestCase;

class HttpRequestTest extends TestCase
{
    public function test_it_normalizes_method_to_uppercase_and_trims_uri(): void
    {
        $request = new HttpRequest('  get ', '  https://example.test/data  ');

        $this->assertSame('GET', $request->method);
        $this->assertSame('https://example.test/data', $request->uri);
        $this->assertFalse($request->hasJsonBody());
    }

    public function test_it_rejects_an_empty_method(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP request method cannot be empty.');

        new HttpRequest('   ', 'https://example.test');
    }

    public function test_it_rejects_an_empty_uri(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP request URI cannot be empty.');

        new HttpRequest('GET', '   ');
    }

    public function test_it_rejects_empty_header_names(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP request header names cannot be empty.');

        new HttpRequest('GET', 'https://example.test', ['' => 'value']);
    }

    public function test_it_rejects_empty_query_parameter_names(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP request query parameter names cannot be empty.');

        new HttpRequest('GET', 'https://example.test', [], ['' => 'value']);
    }

    public function test_it_rejects_a_non_positive_timeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP request timeout must be greater than zero.');

        new HttpRequest('GET', 'https://example.test', timeoutSeconds: 0.0);
    }

    public function test_it_rejects_a_non_positive_connect_timeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP request connect timeout must be greater than zero.');

        new HttpRequest('GET', 'https://example.test', connectTimeoutSeconds: -1.0);
    }

    public function test_with_header_returns_a_copy_with_the_header_added(): void
    {
        $request = new HttpRequest('POST', 'https://example.test', ['Accept' => 'application/json']);

        $authorized = $request->withHeader('Authorization', 'Bearer secret');

        $this->assertNotSame($request, $authorized);
        $this->assertSame(['Accept' => 'application/json'], $request->headers);
        $this->assertSame([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer secret',
        ], $authorized->headers);
    }

    public function test_it_reports_a_json_body(): void
    {
        $request = new HttpRequest('POST', 'https://example.test', jsonBody: ['query' => '{ ok }']);

        $this->assertTrue($request->hasJsonBody());
        $this->assertSame(['query' => '{ ok }'], $request->jsonBody);
    }
}
