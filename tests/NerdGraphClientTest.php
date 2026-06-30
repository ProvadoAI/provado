<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests;

use DateTimeImmutable;
use Mquevedob\Provado\Config\SourceConfig;
use Mquevedob\Provado\Config\SourceCredentials;
use Mquevedob\Provado\Core\EntityReference;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Http\FakeHttpClient;
use Mquevedob\Provado\Http\HttpResponse;
use Mquevedob\Provado\Http\HttpTransportException;
use Mquevedob\Provado\Sources\NewRelic\NerdGraphClient;
use PHPUnit\Framework\TestCase;

class NerdGraphClientTest extends TestCase
{
    public function test_sends_graphql_post_with_api_key_and_windowed_nrql(): void
    {
        $http = (new FakeHttpClient())->respondWith($this->successResponse());
        $window = $this->timeWindow();

        (new NerdGraphClient($http))->fetch($this->credentialedConfig(), $window);

        $request = $http->lastRequest();

        $this->assertNotNull($request);
        $this->assertSame('POST', $request->method);
        $this->assertSame('https://api.newrelic.com/graphql', $request->uri);
        $this->assertSame('nr-secret', $request->headers['API-Key']);
        $this->assertIsArray($request->jsonBody);
        $this->assertStringContainsString('nrql(query: $nrql)', $request->jsonBody['query']);
        $this->assertSame(123456, $request->jsonBody['variables']['accountId']);
        $this->assertStringContainsString('FROM Transaction', $request->jsonBody['variables']['nrql']);
        $this->assertStringContainsString(
            sprintf('SINCE %d UNTIL %d', $window->start->getTimestamp() * 1000, $window->end->getTimestamp() * 1000),
            $request->jsonBody['variables']['nrql'],
        );
    }

    public function test_successful_response_maps_results_to_signals(): void
    {
        $http = (new FakeHttpClient())->respondWith($this->successResponse());

        $result = (new NerdGraphClient($http))->fetch($this->credentialedConfig(), $this->timeWindow());

        $this->assertSame([], $result->errors());
        $this->assertCount(1, $result->signals());

        $signal = $result->signals()[0];
        $this->assertSame('new_relic', $signal->source->value);
        $this->assertSame('transaction_health', $signal->type->value);
        // Entities come from the positional `facet` member, not named columns.
        $this->assertTrue($signal->hasEntity(new EntityReference('service', 'Magento Lab - Provado')));
        $this->assertTrue($signal->hasEntity(new EntityReference('transaction', 'OtherTransaction/Custom/CLI cron:run')));
        $this->assertSame(289, $signal->attributes['throughput']);
        $this->assertSame(6.85, $signal->attributes['duration_ms']);
        $this->assertSame(0, $signal->attributes['error_rate']);
        $this->assertArrayNotHasKey('facet', $signal->attributes);
    }

    public function test_operational_mode_reads_provado_signal_events(): void
    {
        $body = json_encode([
            'data' => ['actor' => ['account' => ['nrql' => ['results' => [
                [
                    'signal' => 'cron_health',
                    'source' => 'magento',
                    'store' => 'default',
                    'missed' => 3,
                    'pending' => 12,
                    'timestamp' => 1_700_000_000_000,
                ],
            ]]]]],
        ]);
        $http = (new FakeHttpClient())->respondWith(new HttpResponse(200, (string) $body));
        $config = new SourceConfig(
            name: 'new_relic',
            enabled: true,
            options: ['account_id' => '123456', 'mode' => 'operational_signals'],
            credentials: new SourceCredentials(['api_key' => 'nr-secret']),
        );

        $result = (new NerdGraphClient($http))->fetch($config, $this->timeWindow());

        // Operational mode queries the ProvadoSignal custom events, with LIMIT MAX
        // so the per-(signal,entity) series is not truncated to the most-recent
        // ~100 events (which would cap dwell). LIMIT precedes the appended SINCE.
        $nrql = $http->lastRequest()->jsonBody['variables']['nrql'];
        $this->assertStringContainsString('FROM ProvadoSignal', $nrql);
        $this->assertStringContainsString('LIMIT MAX', $nrql);
        $this->assertStringContainsString('LIMIT MAX SINCE', $nrql);
        $this->assertSame([], $result->errors());
        $this->assertCount(1, $result->signals());

        $signal = $result->signals()[0];
        $this->assertSame('cron_health', $signal->type->value);
        $this->assertSame('magento', $signal->source->value);
        $this->assertTrue($signal->hasEntity(new EntityReference('store', 'default')));
        $this->assertSame(3, $signal->attributes['missed']);
        $this->assertSame(12, $signal->attributes['pending']);
    }

    public function test_multiple_modes_run_both_queries_and_combine_signals(): void
    {
        // Mode order is [transaction_health, operational_signals]; the fake serves
        // one response per request in order.
        $transactionHealth = json_encode(['data' => ['actor' => ['account' => ['nrql' => ['results' => [
            ['facet' => ['checkout-api', 'WebTransaction/Checkout'], 'throughput' => 10, 'duration_ms' => 1.5, 'error_rate' => 0],
        ]]]]]]);
        $operational = json_encode(['data' => ['actor' => ['account' => ['nrql' => ['results' => [
            ['signal' => 'cron_health', 'source' => 'magento', 'store' => 'default', 'missed' => 3, 'timestamp' => 1_700_000_000_000],
        ]]]]]]);

        $http = (new FakeHttpClient())
            ->respondWith(new HttpResponse(200, (string) $transactionHealth))
            ->respondWith(new HttpResponse(200, (string) $operational));

        $config = new SourceConfig(
            name: 'new_relic',
            enabled: true,
            options: ['account_id' => '123456', 'modes' => ['transaction_health', 'operational_signals']],
            credentials: new SourceCredentials(['api_key' => 'nr-secret']),
        );

        $result = (new NerdGraphClient($http))->fetch($config, $this->timeWindow());

        $this->assertCount(2, $http->sentRequests());
        $this->assertSame([], $result->errors());

        $types = array_map(static fn ($s) => $s->type->value, $result->signals());
        $this->assertContains('transaction_health', $types);
        $this->assertContains('cron_health', $types);
    }

    public function test_row_without_facet_is_reported_as_invalid_row(): void
    {
        $body = json_encode([
            'data' => ['actor' => ['account' => ['nrql' => ['results' => [
                ['throughput' => 10, 'duration_ms' => 1.5],
            ]]]]],
        ]);
        $http = (new FakeHttpClient())->respondWith(new HttpResponse(200, (string) $body));

        $result = (new NerdGraphClient($http))->fetch($this->credentialedConfig(), $this->timeWindow());

        $this->assertSame([], $result->signals());
        $this->assertSame('invalid_nrql_row', $result->errors()[0]->code);
        $this->assertSame(0, $result->errors()[0]->context['row']);
    }

    public function test_graphql_errors_produce_source_fetch_error(): void
    {
        $body = json_encode(['errors' => [['message' => 'NRQL syntax error']]]);
        $http = (new FakeHttpClient())->respondWith(new HttpResponse(200, (string) $body));

        $result = (new NerdGraphClient($http))->fetch($this->credentialedConfig(), $this->timeWindow());

        $this->assertSame([], $result->signals());
        $this->assertTrue($result->hasErrors());
        $this->assertSame('graphql_error', $result->errors()[0]->code);
        $this->assertFalse($result->errors()[0]->retryable);
    }

    public function test_auth_failure_is_not_retryable(): void
    {
        $http = (new FakeHttpClient())->respondWith(new HttpResponse(401, '{}'));

        $result = (new NerdGraphClient($http))->fetch($this->credentialedConfig(), $this->timeWindow());

        $this->assertSame('auth_error', $result->errors()[0]->code);
        $this->assertFalse($result->errors()[0]->retryable);
    }

    public function test_server_error_is_retryable(): void
    {
        $http = (new FakeHttpClient())->respondWith(new HttpResponse(500, ''));

        $result = (new NerdGraphClient($http))->fetch($this->credentialedConfig(), $this->timeWindow());

        $this->assertSame('server_error', $result->errors()[0]->code);
        $this->assertTrue($result->errors()[0]->retryable);
    }

    public function test_rate_limited_is_retryable(): void
    {
        $http = (new FakeHttpClient())->respondWith(new HttpResponse(429, '', ['Retry-After' => '30']));

        $result = (new NerdGraphClient($http))->fetch($this->credentialedConfig(), $this->timeWindow());

        $this->assertSame('rate_limited', $result->errors()[0]->code);
        $this->assertTrue($result->errors()[0]->retryable);
        $this->assertSame(30, $result->errors()[0]->context['retry_after']);
    }

    public function test_transport_failure_is_retryable(): void
    {
        $http = (new FakeHttpClient())->failWith(new HttpTransportException('connection reset', retryable: true));

        $result = (new NerdGraphClient($http))->fetch($this->credentialedConfig(), $this->timeWindow());

        $this->assertSame('transport_error', $result->errors()[0]->code);
        $this->assertTrue($result->errors()[0]->retryable);
    }

    public function test_missing_account_id_errors_without_calling_http(): void
    {
        $http = new FakeHttpClient();
        $config = new SourceConfig(
            name: 'new_relic',
            enabled: true,
            options: [],
            credentials: new SourceCredentials(['api_key' => 'nr-secret']),
        );

        $result = (new NerdGraphClient($http))->fetch($config, $this->timeWindow());

        $this->assertSame([], $http->sentRequests());
        $this->assertSame('missing_account_id', $result->errors()[0]->code);
        $this->assertFalse($result->errors()[0]->retryable);
    }

    public function test_invalid_json_body_is_reported_as_invalid_response(): void
    {
        $http = (new FakeHttpClient())->respondWith(new HttpResponse(200, 'not json'));

        $result = (new NerdGraphClient($http))->fetch($this->credentialedConfig(), $this->timeWindow());

        $this->assertSame([], $result->signals());
        $this->assertSame('invalid_response', $result->errors()[0]->code);
    }

    private function successResponse(): HttpResponse
    {
        $body = json_encode([
            'data' => [
                'actor' => [
                    'account' => [
                        'nrql' => [
                            'results' => [
                                [
                                    // Real NerdGraph multi-facet shape: facet values
                                    // live in the positional `facet` member.
                                    'facet' => ['Magento Lab - Provado', 'OtherTransaction/Custom/CLI cron:run'],
                                    'throughput' => 289,
                                    'duration_ms' => 6.85,
                                    'error_rate' => 0,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        return new HttpResponse(200, (string) $body);
    }

    private function credentialedConfig(): SourceConfig
    {
        return new SourceConfig(
            name: 'new_relic',
            enabled: true,
            options: ['account_id' => '123456'],
            credentials: new SourceCredentials(['api_key' => 'nr-secret']),
        );
    }

    private function timeWindow(): TimeWindow
    {
        return new TimeWindow(
            start: new DateTimeImmutable('2026-06-08T12:00:00+00:00'),
            end: new DateTimeImmutable('2026-06-08T12:30:00+00:00'),
        );
    }
}
