<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Sources\AdobeCommerce;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Mquevedob\Provado\Config\SourceConfig;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Http\HttpClient;
use Mquevedob\Provado\Http\HttpRequest;
use Mquevedob\Provado\Http\HttpResponse;
use Mquevedob\Provado\Http\HttpSourceErrorFactory;
use Mquevedob\Provado\Http\HttpTransportException;
use Mquevedob\Provado\Sources\SourceFetchError;
use Mquevedob\Provado\Sources\SourceFetchResult;

/**
 * Live Adobe Commerce / Magento client over the REST API.
 *
 * Implements the {@see AdobeCommerceClient} seam: the adapter selects it when an
 * `access_token` credential is present, falling back to fixtures otherwise. It
 * fetches recent orders (the Tier-0 "revenue at risk" view) via the REST
 * `/V1/orders` endpoint over the provider-agnostic {@see HttpClient}, scoped to
 * the requested {@see TimeWindow} via `searchCriteria`, and returns canonical
 * {@see SourceFetchResult}s — no REST response shape crosses this boundary.
 *
 * The order → {@see \Mquevedob\Provado\Core\Signal} translation is delegated to
 * {@see AdobeCommercePayloadMapper::mapOrders()}; Phase 4 hardens that mapping
 * (per-status backlog signals) and adds edition detection.
 */
final readonly class AdobeCommerceRestClient implements AdobeCommerceClient
{
    private const SOURCE_NAME = 'adobe_commerce';

    private const DEFAULT_PAGE_SIZE = 100;

    public function __construct(
        private HttpClient $httpClient,
        private AdobeCommercePayloadMapper $mapper = new AdobeCommercePayloadMapper(),
        private HttpSourceErrorFactory $errorFactory = new HttpSourceErrorFactory(),
    ) {
    }

    public function fetch(SourceConfig $config, TimeWindow $window): SourceFetchResult
    {
        $restBase = $this->restBase($config);

        if ($restBase === null) {
            return $this->errorResult(
                'Adobe Commerce base_url option is required for live REST queries.',
                'missing_base_url',
            );
        }

        $signer = $this->signer($config);

        if ($signer === null) {
            return $this->errorResult(
                'Adobe Commerce OAuth 1.0a credentials (consumer_key, consumer_secret, access_token, '
                .'access_token_secret) are required for live REST queries.',
                'missing_credentials',
            );
        }

        $request = $this->buildOrdersRequest($restBase, $signer, $window, $config);

        try {
            $response = $this->httpClient->send($request);
        } catch (HttpTransportException $exception) {
            return SourceFetchResult::empty()->withErrors([
                $this->errorFactory->fromTransportException(self::SOURCE_NAME, $request, $exception),
            ]);
        }

        if (! $response->isSuccessful()) {
            return SourceFetchResult::empty()->withErrors([
                $this->errorFactory->fromResponse(self::SOURCE_NAME, $request, $response),
            ]);
        }

        return $this->mapResponse($window, $response);
    }

    private function buildOrdersRequest(
        string $restBase,
        OAuth1Signer $signer,
        TimeWindow $window,
        SourceConfig $config,
    ): HttpRequest {
        $uri = $restBase.'/V1/orders';
        $query = $this->ordersSearchCriteria($window, $config);

        // The signature must cover the query parameters. Laravel's HTTP client
        // (Guzzle) serializes the query with http_build_query(PHP_QUERY_RFC3986),
        // which is the same rawurlencode rule the signer applies to the base
        // string (space -> %20, brackets -> %5B/%5D), so the wire request matches
        // what was signed. That contract is pinned by
        // AdobeCommerceRestClientTest::test_signed_query_encoding_matches_guzzle_wire_encoding.
        $authorization = $signer->authorizationHeader('GET', $uri, $query, $this->nonce(), $this->timestamp());

        return new HttpRequest(
            method: 'GET',
            uri: $uri,
            headers: [
                'Authorization' => $authorization,
                'Accept' => 'application/json',
            ],
            query: $query,
        );
    }

    /**
     * Build the OAuth 1.0a signer from the four integration credentials, or null
     * when any is absent.
     */
    private function signer(SourceConfig $config): ?OAuth1Signer
    {
        $consumerKey = $config->credentials->get('consumer_key');
        $consumerSecret = $config->credentials->get('consumer_secret');
        $accessToken = $config->credentials->get('access_token');
        $accessTokenSecret = $config->credentials->get('access_token_secret');

        if ($consumerKey === null || $consumerSecret === null || $accessToken === null || $accessTokenSecret === null) {
            return null;
        }

        return new OAuth1Signer($consumerKey, $consumerSecret, $accessToken, $accessTokenSecret);
    }

    private function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function timestamp(): int
    {
        return time();
    }

    /**
     * Magento `searchCriteria` selecting orders whose `created_at` falls within
     * the window. Times are sent in UTC ('Y-m-d H:i:s'), the format the REST API
     * expects for date filters.
     *
     * @return array<string, scalar>
     */
    private function ordersSearchCriteria(TimeWindow $window, SourceConfig $config): array
    {
        return [
            'searchCriteria[filterGroups][0][filters][0][field]' => 'created_at',
            'searchCriteria[filterGroups][0][filters][0][conditionType]' => 'gteq',
            'searchCriteria[filterGroups][0][filters][0][value]' => $this->utc($window->start),
            'searchCriteria[filterGroups][1][filters][0][field]' => 'created_at',
            'searchCriteria[filterGroups][1][filters][0][conditionType]' => 'lteq',
            'searchCriteria[filterGroups][1][filters][0][value]' => $this->utc($window->end),
            'searchCriteria[sortOrders][0][field]' => 'created_at',
            'searchCriteria[sortOrders][0][direction]' => 'DESC',
            'searchCriteria[pageSize]' => $this->pageSize($config),
        ];
    }

    private function mapResponse(TimeWindow $window, HttpResponse $response): SourceFetchResult
    {
        try {
            $decoded = $response->json();
        } catch (JsonException $exception) {
            return $this->errorResult(
                'Adobe Commerce response body was not valid JSON.',
                'invalid_response',
                ['reason' => $exception->getMessage()],
            );
        }

        if (! is_array($decoded) || ! isset($decoded['items']) || ! is_array($decoded['items'])) {
            return $this->errorResult(
                'Adobe Commerce orders response did not contain an items array.',
                'invalid_response',
            );
        }

        try {
            $signals = $this->mapper->mapOrders(array_values($decoded['items']), $window->end);
        } catch (InvalidArgumentException $exception) {
            // Canonical value objects reject bad data with this exception; let
            // genuine programmer errors (TypeError, etc.) surface instead.
            return $this->errorResult(
                'Unable to map Adobe Commerce orders response.',
                'invalid_orders_response',
                ['reason' => $exception->getMessage()],
            );
        }

        return SourceFetchResult::fromSignals($signals);
    }

    private function restBase(SourceConfig $config): ?string
    {
        $baseUrl = $config->option('base_url');

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            return null;
        }

        // Accept the store base URL with or without a trailing /rest (any case);
        // always resolve to the single canonical REST root.
        $trimmed = rtrim(trim($baseUrl), '/');
        $withoutRest = preg_replace('#/rest$#i', '', $trimmed) ?? $trimmed;

        return $withoutRest.'/rest';
    }

    private function pageSize(SourceConfig $config): int
    {
        $pageSize = $config->option('page_size', self::DEFAULT_PAGE_SIZE);

        if (is_int($pageSize) && $pageSize > 0) {
            return $pageSize;
        }

        if (is_string($pageSize) && ctype_digit($pageSize) && (int) $pageSize > 0) {
            return (int) $pageSize;
        }

        return self::DEFAULT_PAGE_SIZE;
    }

    private function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function errorResult(string $message, string $code, array $context = []): SourceFetchResult
    {
        return SourceFetchResult::empty()->withErrors([
            new SourceFetchError(
                sourceName: self::SOURCE_NAME,
                message: $message,
                code: $code,
                retryable: false,
                context: $context,
            ),
        ]);
    }
}
