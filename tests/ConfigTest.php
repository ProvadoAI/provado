<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Provado\Config\ProvadoConfig;
use Provado\Config\SourceCredentials;

require_once __DIR__.'/../src/Config/SourceCredentials.php';
require_once __DIR__.'/../src/Config/SourceConfig.php';
require_once __DIR__.'/../src/Config/ProvadoConfig.php';

class ConfigTest extends TestCase
{
    public function test_valid_config_loads_named_sources(): void
    {
        $config = ProvadoConfig::fromArray([
            'enabled' => true,
            'sources' => [
                'new_relic' => [
                    'enabled' => true,
                    'options' => ['account_id' => '123456'],
                    'credentials' => ['api_key' => 'nr-secret'],
                ],
                'adobe_commerce' => [
                    'enabled' => true,
                    'options' => ['base_url' => 'https://commerce.example.test'],
                    'credentials' => ['access_token' => 'commerce-secret'],
                ],
            ],
        ]);

        $this->assertTrue($config->enabled);
        $this->assertTrue($config->source('new_relic')->enabled);
        $this->assertSame('new_relic', $config->source('new_relic')->name);
        $this->assertSame('123456', $config->source('new_relic')->option('account_id'));
        $this->assertSame('nr-secret', $config->source('new_relic')->credentials->get('api_key'));
        $this->assertSame('adobe_commerce', $config->source('adobe_commerce')->name);
    }

    public function test_disabled_global_config_does_not_validate_source_credentials(): void
    {
        $config = ProvadoConfig::fromArray([
            'enabled' => false,
            'sources' => [
                'new_relic' => [
                    'enabled' => true,
                    'options' => [],
                    'credentials' => [],
                ],
            ],
        ]);

        $this->assertFalse($config->enabled);
        $this->assertTrue($config->source('new_relic')->enabled);
    }

    public function test_disabled_sources_do_not_require_credentials(): void
    {
        $config = ProvadoConfig::fromArray([
            'enabled' => true,
            'sources' => [
                'new_relic' => [
                    'enabled' => false,
                    'options' => [],
                    'credentials' => [],
                ],
            ],
        ]);

        $this->assertTrue($config->enabled);
        $this->assertFalse($config->source('new_relic')->enabled);
        $this->assertFalse($config->source('adobe_commerce')->enabled);
    }

    public function test_enabled_sources_reject_missing_required_credentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Source "new_relic" is missing required credential "api_key".');

        ProvadoConfig::fromArray([
            'enabled' => true,
            'sources' => [
                'new_relic' => [
                    'enabled' => true,
                    'options' => ['account_id' => '123456'],
                    'credentials' => [],
                ],
            ],
        ]);
    }

    public function test_credentials_are_redacted_in_string_json_and_array_exports(): void
    {
        $credentials = new SourceCredentials([
            'api_key' => 'nr-secret',
            'access_token' => 'commerce-secret',
        ]);

        $this->assertSame('nr-secret', $credentials->get('api_key'));
        $this->assertSame('[redacted]', (string) $credentials);
        $this->assertSame([
            'api_key' => '[redacted]',
            'access_token' => '[redacted]',
        ], $credentials->toArray());
        $this->assertStringNotContainsString('nr-secret', json_encode($credentials, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('commerce-secret', json_encode($credentials, JSON_THROW_ON_ERROR));
    }

    public function test_config_exports_redact_nested_credentials(): void
    {
        $config = ProvadoConfig::fromArray([
            'enabled' => true,
            'sources' => [
                'new_relic' => [
                    'enabled' => true,
                    'options' => ['account_id' => '123456'],
                    'credentials' => ['api_key' => 'nr-secret'],
                ],
            ],
        ]);

        $this->assertSame('[redacted]', $config->toArray()['sources']['new_relic']['credentials']['api_key']);
        $this->assertStringNotContainsString('nr-secret', json_encode($config, JSON_THROW_ON_ERROR));
    }
}
