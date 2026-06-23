<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests\Contracts;

use Mquevedob\Provado\Http\FakeHttpClient;
use Mquevedob\Provado\Http\HttpRequest;
use Mquevedob\Provado\Http\HttpSourceErrorFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RecordedResponseLoaderTest extends TestCase
{
    public function test_it_loads_a_recording_whose_body_reuses_an_existing_fixture(): void
    {
        $response = (new RecordedResponseLoader())->load('new_relic', 'latency_spike');

        $this->assertSame(200, $response->status);
        $this->assertSame('application/json', $response->header('Content-Type'));

        $payload = $response->json();
        $this->assertIsArray($payload);
        $this->assertSame('latency_spike', $payload['id']);
        $this->assertSame('latency_spike', $payload['event_type']);
    }

    public function test_a_recording_can_be_replayed_through_the_fake_http_client(): void
    {
        $loader = new RecordedResponseLoader();
        $client = new FakeHttpClient();
        $client->respondWith($loader->load('adobe_commerce', 'checkout_failure_rate'));

        $response = $client->send(new HttpRequest('GET', 'https://commerce.example.test/checkout'));

        $payload = $response->json();
        $this->assertIsArray($payload);
        $this->assertSame('checkout_failure_rate', $payload['event_type']);
    }

    public function test_an_error_recording_drives_the_retryable_classification(): void
    {
        $request = new HttpRequest('POST', 'https://api.newrelic.test/graphql');
        $response = (new RecordedResponseLoader())->load('new_relic', 'rate_limited');

        $this->assertSame(429, $response->status);
        $this->assertTrue($response->isTooManyRequests());
        $this->assertSame(30, $response->retryAfterSeconds());

        $error = (new HttpSourceErrorFactory())->fromResponse('new_relic', $request, $response);

        $this->assertSame('rate_limited', $error->code);
        $this->assertTrue($error->retryable);
        $this->assertSame(30, $error->context['retry_after']);
    }

    public function test_it_throws_for_a_missing_recording(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to read contract file');

        (new RecordedResponseLoader())->load('new_relic', 'does_not_exist');
    }
}
