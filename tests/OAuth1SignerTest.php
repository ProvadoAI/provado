<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests;

use Mquevedob\Provado\Sources\AdobeCommerce\OAuth1Signer;
use PHPUnit\Framework\TestCase;

class OAuth1SignerTest extends TestCase
{
    public function test_builds_oauth_header_with_required_parameters(): void
    {
        $header = $this->signer()->authorizationHeader(
            'GET',
            'https://shop.test/rest/V1/orders',
            ['searchCriteria[pageSize]' => '5'],
            'nonce123',
            1_700_000_000,
        );

        $this->assertStringStartsWith('OAuth ', $header);
        $this->assertStringContainsString('oauth_consumer_key="ck"', $header);
        $this->assertStringContainsString('oauth_token="atok"', $header);
        $this->assertStringContainsString('oauth_signature_method="HMAC-SHA256"', $header);
        $this->assertStringContainsString('oauth_nonce="nonce123"', $header);
        $this->assertStringContainsString('oauth_timestamp="1700000000"', $header);
        $this->assertStringContainsString('oauth_version="1.0"', $header);
        $this->assertMatchesRegularExpression('/oauth_signature="[^"]+"/', $header);
    }

    public function test_signature_is_deterministic_for_identical_input(): void
    {
        $args = ['GET', 'https://shop.test/rest/V1/orders', ['searchCriteria[pageSize]' => '5'], 'nonce123', 1_700_000_000];

        $this->assertSame(
            $this->signer()->authorizationHeader(...$args),
            $this->signer()->authorizationHeader(...$args),
        );
    }

    public function test_signature_changes_when_query_changes(): void
    {
        $a = $this->signer()->authorizationHeader('GET', 'https://shop.test/rest/V1/orders', ['searchCriteria[pageSize]' => '5'], 'n', 1);
        $b = $this->signer()->authorizationHeader('GET', 'https://shop.test/rest/V1/orders', ['searchCriteria[pageSize]' => '6'], 'n', 1);

        $this->assertNotSame($this->signatureOf($a), $this->signatureOf($b));
    }

    public function test_signature_changes_when_nonce_changes(): void
    {
        $a = $this->signer()->authorizationHeader('GET', 'https://shop.test/rest/V1/orders', [], 'nonce-a', 1);
        $b = $this->signer()->authorizationHeader('GET', 'https://shop.test/rest/V1/orders', [], 'nonce-b', 1);

        $this->assertNotSame($this->signatureOf($a), $this->signatureOf($b));
    }

    private function signer(): OAuth1Signer
    {
        return new OAuth1Signer('ck', 'cs', 'atok', 'ats');
    }

    private function signatureOf(string $header): string
    {
        $this->assertSame(1, preg_match('/oauth_signature="([^"]+)"/', $header, $m));

        return $m[1];
    }
}
