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
use Mquevedob\Provado\Sources\AdobeCommerce\AdobeCommerceRestClient;
use PHPUnit\Framework\TestCase;

class AdobeCommerceRestClientTest extends TestCase
{
    public function test_sends_oauth_signed_get_to_normalized_rest_orders_endpoint(): void
    {
        $http = (new FakeHttpClient())->respondWith($this->ordersResponse());

        // base_url without /rest — the client must normalize to the REST root.
        (new AdobeCommerceRestClient($http))->fetch($this->credentialedConfig('https://shop.test'), $this->timeWindow());

        $request = $http->lastRequest();

        $this->assertNotNull($request);
        $this->assertSame('GET', $request->method);
        $this->assertSame('https://shop.test/rest/V1/orders', $request->uri);
        // OAuth 1.0a signed Authorization header (not a plain Bearer token).
        $this->assertStringStartsWith('OAuth ', $request->headers['Authorization']);
        $this->assertStringContainsString('oauth_consumer_key="ck"', $request->headers['Authorization']);
        $this->assertStringContainsString('oauth_token="atok"', $request->headers['Authorization']);
        $this->assertStringContainsString('oauth_signature_method="HMAC-SHA256"', $request->headers['Authorization']);
        $this->assertStringContainsString('oauth_signature=', $request->headers['Authorization']);
        $this->assertSame('created_at', $request->query['searchCriteria[filterGroups][0][filters][0][field]']);
        $this->assertSame('gteq', $request->query['searchCriteria[filterGroups][0][filters][0][conditionType]']);
        $this->assertSame('2026-06-08 12:00:00', $request->query['searchCriteria[filterGroups][0][filters][0][value]']);
        $this->assertSame('2026-06-08 12:30:00', $request->query['searchCriteria[filterGroups][1][filters][0][value]']);
    }

    public function test_base_url_with_trailing_rest_is_not_doubled(): void
    {
        $http = (new FakeHttpClient())->respondWith($this->ordersResponse());

        (new AdobeCommerceRestClient($http))->fetch($this->credentialedConfig('https://shop.test/rest/'), $this->timeWindow());

        $this->assertSame('https://shop.test/rest/V1/orders', $http->lastRequest()?->uri);
    }

    public function test_orders_map_to_aggregate_signals_per_store(): void
    {
        $http = (new FakeHttpClient())->respondWith($this->ordersResponse());

        $result = (new AdobeCommerceRestClient($http))->fetch($this->credentialedConfig(), $this->timeWindow());

        $this->assertSame([], $result->errors());
        $this->assertCount(1, $result->signals());

        $signal = $result->signals()[0];
        $this->assertSame('adobe_commerce', $signal->source->value);
        $this->assertSame('order_activity', $signal->type->value);
        $this->assertTrue($signal->hasEntity(new EntityReference('store', '1')));
        $this->assertSame(2, $signal->attributes['order_count']);
        $this->assertSame(149.75, $signal->attributes['gross_total']);
        // Per-status breakdown carried as attributes of the one order_activity signal.
        $this->assertSame(1, $signal->attributes['count_complete']);
        $this->assertSame(1, $signal->attributes['count_pending']);
    }

    public function test_orders_without_status_are_counted_as_unknown(): void
    {
        $body = json_encode([
            'items' => [
                ['entity_id' => 10, 'store_id' => 1, 'grand_total' => 10, 'status' => 'holded'],
                ['entity_id' => 11, 'store_id' => 1, 'grand_total' => 20],
            ],
            'total_count' => 2,
        ]);
        $http = (new FakeHttpClient())->respondWith(new HttpResponse(200, (string) $body));

        $result = (new AdobeCommerceRestClient($http))->fetch($this->credentialedConfig(), $this->timeWindow());

        $attributes = $result->signals()[0]->attributes;
        $this->assertSame(2, $attributes['order_count']);
        $this->assertSame(1, $attributes['count_holded']);
        $this->assertSame(1, $attributes['count_unknown']);
    }

    public function test_signed_query_encoding_matches_guzzle_wire_encoding(): void
    {
        $http = (new FakeHttpClient())->respondWith($this->ordersResponse());

        (new AdobeCommerceRestClient($http))->fetch($this->credentialedConfig(), $this->timeWindow());
        $request = $http->lastRequest();
        $this->assertNotNull($request);

        // Laravel's HTTP client (Guzzle) serializes the query with this exact
        // call; the OAuth signer signs the same params with per-pair rawurlencode.
        // This pins that the wire encoding matches the signed base string, so a
        // divergence (e.g. RFC 1738 encoding a space as "+") is caught here rather
        // than as a live "signature is invalid".
        $wire = http_build_query($request->query, '', '&', PHP_QUERY_RFC3986);

        foreach ($request->query as $key => $value) {
            $this->assertStringContainsString(
                rawurlencode((string) $key).'='.rawurlencode((string) $value),
                $wire,
            );
        }

        // The created_at filter value contains a space — it must encode as %20.
        $this->assertStringContainsString('2026-06-08%2012%3A00%3A00', $wire);
        $this->assertStringNotContainsString('+', $wire);
    }

    public function test_base_url_with_uppercase_rest_is_normalized(): void
    {
        $http = (new FakeHttpClient())->respondWith($this->ordersResponse());

        (new AdobeCommerceRestClient($http))->fetch($this->credentialedConfig('https://shop.test/REST'), $this->timeWindow());

        $this->assertSame('https://shop.test/rest/V1/orders', $http->lastRequest()?->uri);
    }

    public function test_grand_total_as_numeric_string_is_summed(): void
    {
        $body = json_encode([
            'items' => [
                ['entity_id' => 10, 'store_id' => 1, 'grand_total' => '99.50'],
                ['entity_id' => 11, 'store_id' => 1, 'grand_total' => '50.25'],
            ],
            'total_count' => 2,
        ]);
        $http = (new FakeHttpClient())->respondWith(new HttpResponse(200, (string) $body));

        $result = (new AdobeCommerceRestClient($http))->fetch($this->credentialedConfig(), $this->timeWindow());

        $this->assertCount(1, $result->signals());
        $this->assertSame(2, $result->signals()[0]->attributes['order_count']);
        $this->assertSame(149.75, $result->signals()[0]->attributes['gross_total']);
    }

    public function test_auth_failure_is_not_retryable(): void
    {
        $http = (new FakeHttpClient())->respondWith(new HttpResponse(401, '{"message":"unauthorized"}'));

        $result = (new AdobeCommerceRestClient($http))->fetch($this->credentialedConfig(), $this->timeWindow());

        $this->assertSame('auth_error', $result->errors()[0]->code);
        $this->assertFalse($result->errors()[0]->retryable);
    }

    public function test_server_error_is_retryable(): void
    {
        $http = (new FakeHttpClient())->respondWith(new HttpResponse(503, ''));

        $result = (new AdobeCommerceRestClient($http))->fetch($this->credentialedConfig(), $this->timeWindow());

        $this->assertSame('server_error', $result->errors()[0]->code);
        $this->assertTrue($result->errors()[0]->retryable);
    }

    public function test_transport_failure_is_retryable(): void
    {
        $http = (new FakeHttpClient())->failWith(new HttpTransportException('timeout', retryable: true));

        $result = (new AdobeCommerceRestClient($http))->fetch($this->credentialedConfig(), $this->timeWindow());

        $this->assertSame('transport_error', $result->errors()[0]->code);
        $this->assertTrue($result->errors()[0]->retryable);
    }

    public function test_missing_base_url_errors_without_calling_http(): void
    {
        $http = new FakeHttpClient();
        $config = new SourceConfig(
            name: 'adobe_commerce',
            enabled: true,
            options: [],
            credentials: new SourceCredentials($this->oauthCredentials()),
        );

        $result = (new AdobeCommerceRestClient($http))->fetch($config, $this->timeWindow());

        $this->assertSame([], $http->sentRequests());
        $this->assertSame('missing_base_url', $result->errors()[0]->code);
    }

    public function test_incomplete_oauth_credentials_error_without_calling_http(): void
    {
        $http = new FakeHttpClient();
        // access_token present but the OAuth secrets are missing.
        $config = new SourceConfig(
            name: 'adobe_commerce',
            enabled: true,
            options: ['base_url' => 'https://shop.test'],
            credentials: new SourceCredentials(['access_token' => 'atok']),
        );

        $result = (new AdobeCommerceRestClient($http))->fetch($config, $this->timeWindow());

        $this->assertSame([], $http->sentRequests());
        $this->assertSame('missing_credentials', $result->errors()[0]->code);
    }

    public function test_response_without_items_is_invalid(): void
    {
        $http = (new FakeHttpClient())->respondWith(new HttpResponse(200, '{"total_count":0}'));

        $result = (new AdobeCommerceRestClient($http))->fetch($this->credentialedConfig(), $this->timeWindow());

        $this->assertSame([], $result->signals());
        $this->assertSame('invalid_response', $result->errors()[0]->code);
    }

    public function test_empty_items_yields_no_signals_and_no_errors(): void
    {
        $http = (new FakeHttpClient())->respondWith(new HttpResponse(200, '{"items":[],"total_count":0}'));

        $result = (new AdobeCommerceRestClient($http))->fetch($this->credentialedConfig(), $this->timeWindow());

        $this->assertSame([], $result->signals());
        $this->assertSame([], $result->errors());
    }

    private function ordersResponse(): HttpResponse
    {
        $body = json_encode([
            'items' => [
                ['entity_id' => 10, 'store_id' => 1, 'grand_total' => 99.5, 'status' => 'complete'],
                ['entity_id' => 11, 'store_id' => 1, 'grand_total' => 50.25, 'status' => 'pending'],
            ],
            'total_count' => 2,
        ]);

        return new HttpResponse(200, (string) $body);
    }

    private function credentialedConfig(string $baseUrl = 'https://shop.test'): SourceConfig
    {
        return new SourceConfig(
            name: 'adobe_commerce',
            enabled: true,
            options: ['base_url' => $baseUrl],
            credentials: new SourceCredentials($this->oauthCredentials()),
        );
    }

    /**
     * @return array<string, string>
     */
    private function oauthCredentials(): array
    {
        return [
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
            'access_token' => 'atok',
            'access_token_secret' => 'ats',
        ];
    }

    private function timeWindow(): TimeWindow
    {
        return new TimeWindow(
            start: new DateTimeImmutable('2026-06-08T12:00:00+00:00'),
            end: new DateTimeImmutable('2026-06-08T12:30:00+00:00'),
        );
    }
}
