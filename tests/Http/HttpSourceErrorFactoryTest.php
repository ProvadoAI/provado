<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests\Http;

use Mquevedob\Provado\Http\HttpRequest;
use Mquevedob\Provado\Http\HttpResponse;
use Mquevedob\Provado\Http\HttpSourceErrorFactory;
use Mquevedob\Provado\Http\HttpTransportException;
use PHPUnit\Framework\TestCase;

class HttpSourceErrorFactoryTest extends TestCase
{
    private HttpSourceErrorFactory $factory;

    private HttpRequest $request;

    protected function setUp(): void
    {
        $this->factory = new HttpSourceErrorFactory();
        $this->request = new HttpRequest('GET', 'https://example.test/data', query: ['token' => 'secret']);
    }

    public function test_retryable_status_classification(): void
    {
        $this->assertTrue(HttpSourceErrorFactory::isRetryableStatus(429));
        $this->assertTrue(HttpSourceErrorFactory::isRetryableStatus(500));
        $this->assertTrue(HttpSourceErrorFactory::isRetryableStatus(503));
        $this->assertFalse(HttpSourceErrorFactory::isRetryableStatus(401));
        $this->assertFalse(HttpSourceErrorFactory::isRetryableStatus(404));
        $this->assertFalse(HttpSourceErrorFactory::isRetryableStatus(200));
    }

    public function test_server_error_is_retryable(): void
    {
        $error = $this->factory->fromResponse('new_relic', $this->request, new HttpResponse(502));

        $this->assertSame('new_relic', $error->sourceName);
        $this->assertSame('server_error', $error->code);
        $this->assertTrue($error->retryable);
        $this->assertSame(502, $error->context['status']);
        $this->assertSame('GET', $error->context['method']);
        $this->assertSame('https://example.test/data', $error->context['uri']);
    }

    public function test_rate_limit_is_retryable_and_carries_retry_after(): void
    {
        $response = new HttpResponse(429, '', ['Retry-After' => '30']);

        $error = $this->factory->fromResponse('adobe_commerce', $this->request, $response);

        $this->assertSame('rate_limited', $error->code);
        $this->assertTrue($error->retryable);
        $this->assertSame(30, $error->context['retry_after']);
    }

    public function test_auth_failure_is_not_retryable(): void
    {
        $error = $this->factory->fromResponse('new_relic', $this->request, new HttpResponse(401));

        $this->assertSame('auth_error', $error->code);
        $this->assertFalse($error->retryable);
    }

    public function test_other_client_error_is_not_retryable(): void
    {
        $error = $this->factory->fromResponse('new_relic', $this->request, new HttpResponse(404));

        $this->assertSame('client_error', $error->code);
        $this->assertFalse($error->retryable);
    }

    public function test_transport_failure_uses_exception_retryable_flag(): void
    {
        $exception = new HttpTransportException('connection timed out', retryable: true);

        $error = $this->factory->fromTransportException('new_relic', $this->request, $exception);

        $this->assertSame('transport_error', $error->code);
        $this->assertTrue($error->retryable);
        $this->assertSame('connection timed out', $error->context['reason']);
        $this->assertSame('https://example.test/data', $error->context['uri']);
    }

    public function test_error_context_redacts_secret_keys_supplied_by_the_caller(): void
    {
        $error = $this->factory->fromResponse('new_relic', $this->request, new HttpResponse(500), [
            'api_key' => 'nr-secret',
        ]);

        $this->assertSame('[redacted]', $error->context['api_key']);
    }
}
