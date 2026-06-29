<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Mquevedob\Provado\Config\ProvadoConfig;
use Mquevedob\Provado\Config\SourceConfig;
use Mquevedob\Provado\Core\EntityReference;
use Mquevedob\Provado\Core\RawPayloadReference;
use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Core\SignalId;
use Mquevedob\Provado\Core\SignalSeverity;
use Mquevedob\Provado\Core\SignalSource;
use Mquevedob\Provado\Core\SignalType;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Sources\SourceAdapter;
use Mquevedob\Provado\Sources\SourceAdapterRegistry;
use Mquevedob\Provado\Sources\SourceFetchError;
use Mquevedob\Provado\Sources\SourceFetchResult;
use PHPUnit\Framework\TestCase;
use stdClass;

class SourceAdapterTest extends TestCase
{
    public function test_source_fetch_result_with_signals(): void
    {
        $signal = $this->signal('signal-1');

        $result = SourceFetchResult::fromSignals([$signal]);

        $this->assertSame([$signal], $result->signals());
        $this->assertSame([], $result->errors());
        $this->assertFalse($result->hasErrors());
    }

    public function test_source_fetch_result_with_errors(): void
    {
        $error = new SourceFetchError(
            sourceName: 'new_relic',
            message: 'Fetch failed.',
            code: 'provider_timeout',
            retryable: true,
            context: ['account_id' => '123456'],
        );

        $result = SourceFetchResult::empty()->withErrors([$error]);

        $this->assertSame([], $result->signals());
        $this->assertSame([$error], $result->errors());
        $this->assertTrue($result->hasErrors());
    }

    public function test_source_adapter_registry_rejects_invalid_adapter_entries(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Source adapter registry entries must implement SourceAdapter.');

        new SourceAdapterRegistry([new stdClass()]);
    }

    public function test_source_adapter_registry_resolves_adapter_by_name(): void
    {
        $adapter = new FakeSourceAdapter('new_relic');
        $registry = new SourceAdapterRegistry([$adapter]);

        $this->assertSame($adapter, $registry->adapter('new_relic'));
    }

    public function test_source_adapter_registry_returns_only_adapters_supported_by_enabled_source_configs(): void
    {
        $newRelicAdapter = new FakeSourceAdapter('new_relic');
        $adobeCommerceAdapter = new FakeSourceAdapter('adobe_commerce', false);
        $registry = new SourceAdapterRegistry([$newRelicAdapter, $adobeCommerceAdapter]);

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
                    'credentials' => [
                        'consumer_key' => 'ck',
                        'consumer_secret' => 'cs',
                        'access_token' => 'commerce-secret',
                        'access_token_secret' => 'ats',
                    ],
                ],
            ],
        ]);

        $this->assertSame(['new_relic' => $newRelicAdapter], $registry->enabledAdaptersFor($config));
    }

    public function test_source_fetch_error_context_does_not_expose_credentials(): void
    {
        $error = new SourceFetchError(
            sourceName: 'new_relic',
            message: 'Fetch failed.',
            context: [
                'account_id' => '123456',
                'api_key' => 'nr-secret',
                'nested' => [
                    'access_token' => 'commerce-secret',
                    'safe' => 'kept',
                ],
            ],
        );

        $encodedContext = json_encode($error->context, JSON_THROW_ON_ERROR);

        $this->assertSame('123456', $error->context['account_id']);
        $this->assertSame('[redacted]', $error->context['api_key']);
        $this->assertSame('[redacted]', $error->context['nested']['access_token']);
        $this->assertSame('kept', $error->context['nested']['safe']);
        $this->assertStringNotContainsString('nr-secret', $encodedContext);
        $this->assertStringNotContainsString('commerce-secret', $encodedContext);
    }

    private function signal(string $id): Signal
    {
        return new Signal(
            id: new SignalId($id),
            source: new SignalSource('new_relic'),
            type: new SignalType('latency_spike'),
            timestamp: new DateTimeImmutable('2026-06-08T12:00:00+00:00'),
            severity: SignalSeverity::warning(),
            entityReferences: [new EntityReference('service', 'checkout')],
            attributes: ['duration_ms' => 1250],
            rawPayloadReference: new RawPayloadReference('payload-1'),
        );
    }
}

final readonly class FakeSourceAdapter implements SourceAdapter
{
    public function __construct(
        private string $name,
        private bool $supported = true,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function supports(SourceConfig $config): bool
    {
        return $this->supported && $config->name === $this->name;
    }

    public function fetch(SourceConfig $config, TimeWindow $window): SourceFetchResult
    {
        return SourceFetchResult::empty();
    }
}
