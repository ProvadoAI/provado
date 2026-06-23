<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests\Http;

use InvalidArgumentException;
use JsonException;
use Mquevedob\Provado\Http\HttpResponse;
use PHPUnit\Framework\TestCase;

class HttpResponseTest extends TestCase
{
    public function test_it_rejects_an_out_of_range_status(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP response status must be a valid status code.');

        new HttpResponse(99);
    }

    public function test_status_class_helpers(): void
    {
        $this->assertTrue((new HttpResponse(204))->isSuccessful());
        $this->assertTrue((new HttpResponse(404))->isClientError());
        $this->assertTrue((new HttpResponse(503))->isServerError());
        $this->assertTrue((new HttpResponse(429))->isTooManyRequests());
        $this->assertFalse((new HttpResponse(200))->isClientError());
    }

    public function test_header_lookup_is_case_insensitive(): void
    {
        $response = new HttpResponse(200, '', ['Content-Type' => 'application/json']);

        $this->assertSame('application/json', $response->header('content-type'));
        $this->assertNull($response->header('X-Missing'));
    }

    public function test_json_decodes_the_body(): void
    {
        $response = new HttpResponse(200, '{"data":{"ok":true}}');

        $this->assertSame(['data' => ['ok' => true]], $response->json());
    }

    public function test_json_returns_null_for_an_empty_body(): void
    {
        $this->assertNull((new HttpResponse(204))->json());
    }

    public function test_json_throws_on_invalid_json(): void
    {
        $this->expectException(JsonException::class);

        (new HttpResponse(200, '{not json'))->json();
    }

    public function test_retry_after_parses_delta_seconds(): void
    {
        $response = new HttpResponse(429, '', ['Retry-After' => '120']);

        $this->assertSame(120, $response->retryAfterSeconds());
    }

    public function test_retry_after_parses_an_http_date(): void
    {
        $response = new HttpResponse(429, '', [
            'Retry-After' => gmdate('D, d M Y H:i:s', time() + 120).' GMT',
        ]);

        $seconds = $response->retryAfterSeconds();

        $this->assertNotNull($seconds);
        $this->assertGreaterThanOrEqual(110, $seconds);
        $this->assertLessThanOrEqual(120, $seconds);
    }

    public function test_retry_after_is_null_when_absent_or_unparseable(): void
    {
        $this->assertNull((new HttpResponse(429))->retryAfterSeconds());
        $this->assertNull((new HttpResponse(429, '', ['Retry-After' => 'soon']))->retryAfterSeconds());
    }
}
