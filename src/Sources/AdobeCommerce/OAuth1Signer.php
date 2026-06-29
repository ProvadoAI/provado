<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Sources\AdobeCommerce;

/**
 * OAuth 1.0a request signer for the Adobe Commerce / Magento REST API.
 *
 * Magento integrations authenticate with OAuth 1.0a (HMAC-SHA256): a plain
 * Bearer access token authenticates the consumer but is not honored for the
 * integration's ACL, so every call must be signed. The signature base string
 * includes the query parameters alongside the oauth_* parameters — omitting the
 * query yields "signature is invalid".
 *
 * This class only builds the `Authorization` header value; it performs no I/O.
 * The nonce and timestamp are passed in so the signature is deterministic and
 * unit-testable.
 */
final readonly class OAuth1Signer
{
    private const SIGNATURE_METHOD = 'HMAC-SHA256';

    public function __construct(
        private string $consumerKey,
        private string $consumerSecret,
        private string $accessToken,
        private string $accessTokenSecret,
    ) {
    }

    /**
     * Build the `Authorization: OAuth ...` header value for a request.
     *
     * @param string $method HTTP method (e.g. GET)
     * @param string $url request URL without query string or fragment
     * @param array<string, scalar> $query query parameters sent on the wire
     */
    public function authorizationHeader(string $method, string $url, array $query, string $nonce, int $timestamp): string
    {
        $oauthParams = [
            'oauth_consumer_key' => $this->consumerKey,
            'oauth_nonce' => $nonce,
            'oauth_signature_method' => self::SIGNATURE_METHOD,
            'oauth_timestamp' => (string) $timestamp,
            'oauth_token' => $this->accessToken,
            'oauth_version' => '1.0',
        ];

        $oauthParams['oauth_signature'] = $this->signature($method, $url, $query, $oauthParams);

        $header = [];
        foreach ($oauthParams as $name => $value) {
            $header[] = $name.'="'.rawurlencode((string) $value).'"';
        }

        return 'OAuth '.implode(', ', $header);
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, string> $oauthParams
     */
    private function signature(string $method, string $url, array $query, array $oauthParams): string
    {
        // Every request parameter — query plus oauth_* (excluding the signature
        // itself) — participates in the base string, sorted by encoded key.
        $params = array_merge($query, $oauthParams);
        uksort($params, static fn ($a, $b): int => strcmp(rawurlencode((string) $a), rawurlencode((string) $b)));

        $encodedPairs = [];
        foreach ($params as $name => $value) {
            $encodedPairs[] = rawurlencode((string) $name).'='.rawurlencode((string) $value);
        }

        $baseString = strtoupper($method)
            .'&'.rawurlencode($url)
            .'&'.rawurlencode(implode('&', $encodedPairs));

        $signingKey = rawurlencode($this->consumerSecret).'&'.rawurlencode($this->accessTokenSecret);

        return base64_encode(hash_hmac('sha256', $baseString, $signingKey, true));
    }
}
