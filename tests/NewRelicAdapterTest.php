<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests;

use DateTimeImmutable;
use Mquevedob\Provado\Config\SourceConfig;
use Mquevedob\Provado\Config\SourceCredentials;
use Mquevedob\Provado\Core\EntityReference;
use Mquevedob\Provado\Core\RawPayloadReference;
use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Core\SignalId;
use Mquevedob\Provado\Core\SignalSeverity;
use Mquevedob\Provado\Core\SignalSource;
use Mquevedob\Provado\Core\SignalType;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Sources\NewRelic\NewRelicAdapter;
use Mquevedob\Provado\Sources\NewRelic\NewRelicClient;
use Mquevedob\Provado\Sources\SourceAdapter;
use Mquevedob\Provado\Sources\SourceFetchResult;
use PHPUnit\Framework\TestCase;

class NewRelicAdapterTest extends TestCase
{
    public function test_adapter_name(): void
    {
        $adapter = new NewRelicAdapter();

        $this->assertInstanceOf(SourceAdapter::class, $adapter);
        $this->assertSame('new_relic', $adapter->name());
    }

    public function test_supports_only_new_relic_source_config(): void
    {
        $adapter = new NewRelicAdapter();

        $this->assertTrue($adapter->supports($this->sourceConfig('new_relic')));
        $this->assertFalse($adapter->supports($this->sourceConfig('adobe_commerce')));
    }

    public function test_fetch_returns_source_fetch_result(): void
    {
        $adapter = new NewRelicAdapter();

        $result = $adapter->fetch($this->sourceConfig('new_relic'), $this->timeWindow());

        $this->assertInstanceOf(SourceFetchResult::class, $result);
        $this->assertCount(3, $result->signals());
        $this->assertSame([], $result->errors());
    }

    public function test_fixture_latency_payload_maps_to_signal(): void
    {
        $result = (new NewRelicAdapter())->fetch(
            $this->sourceConfig('new_relic', ['fixtures' => ['latency_spike']]),
            $this->timeWindow(),
        );

        $signal = $result->signals()[0];

        $this->assertSame('new_relic:latency_spike', $signal->id->value);
        $this->assertSame('new_relic', $signal->source->value);
        $this->assertSame('latency_spike', $signal->type->value);
        $this->assertSame('warning', $signal->severity->value);
        $this->assertTrue($signal->hasEntity(new EntityReference('service', 'checkout-api')));
        $this->assertSame(2450, $signal->attributes['duration_ms']);
        $this->assertSame(650, $signal->attributes['baseline_duration_ms']);
        $this->assertSame(128, $signal->attributes['throughput']);
        $this->assertSame('latency_spike', $signal->rawPayloadReference->id);
    }

    public function test_fixture_error_rate_payload_maps_to_signal(): void
    {
        $result = (new NewRelicAdapter())->fetch(
            $this->sourceConfig('new_relic', ['fixtures' => ['error_rate_spike']]),
            $this->timeWindow(),
        );

        $signal = $result->signals()[0];

        $this->assertSame('new_relic', $signal->source->value);
        $this->assertSame('error_rate_spike', $signal->type->value);
        $this->assertSame('error', $signal->severity->value);
        $this->assertTrue($signal->hasEntity(new EntityReference('service', 'checkout-api')));
        $this->assertSame(0.084, $signal->attributes['error_rate']);
        $this->assertSame(0.012, $signal->attributes['baseline_error_rate']);
        $this->assertSame(94, $signal->attributes['throughput']);
        $this->assertSame('error_rate_spike', $signal->rawPayloadReference->id);
    }

    public function test_fixture_checkout_slowdown_payload_maps_to_signal(): void
    {
        $result = (new NewRelicAdapter())->fetch(
            $this->sourceConfig('new_relic', ['fixtures' => ['checkout_transaction_slowdown']]),
            $this->timeWindow(),
        );

        $signal = $result->signals()[0];

        $this->assertSame('new_relic', $signal->source->value);
        $this->assertSame('transaction_slowdown', $signal->type->value);
        $this->assertSame('critical', $signal->severity->value);
        $this->assertTrue($signal->hasEntity(new EntityReference('service', 'checkout-api')));
        $this->assertTrue($signal->hasEntity(new EntityReference('transaction', 'WebTransaction/Checkout/SubmitOrder')));
        $this->assertSame(5200, $signal->attributes['duration_ms']);
        $this->assertSame(900, $signal->attributes['baseline_duration_ms']);
        $this->assertSame(43, $signal->attributes['throughput']);
        $this->assertSame('checkout_transaction_slowdown', $signal->rawPayloadReference->id);
    }

    public function test_invalid_fixture_payload_produces_source_fetch_error(): void
    {
        $result = (new NewRelicAdapter())->fetch(
            $this->sourceConfig('new_relic', ['fixtures' => ['invalid_payload']]),
            $this->timeWindow(),
        );

        $this->assertSame([], $result->signals());
        $this->assertTrue($result->hasErrors());
        $this->assertCount(1, $result->errors());
        $this->assertSame('new_relic', $result->errors()[0]->sourceName);
        $this->assertSame('invalid_fixture_payload', $result->errors()[0]->code);
        $this->assertSame('invalid_payload', $result->errors()[0]->context['fixture']);
    }

    public function test_credentialed_client_is_used_when_credentials_are_present(): void
    {
        $client = new StubNewRelicClient(SourceFetchResult::fromSignals([$this->liveSignal()]));
        $adapter = new NewRelicAdapter(client: $client);

        $result = $adapter->fetch($this->credentialedConfig(), $this->timeWindow());

        $this->assertSame(1, $client->calls);
        $this->assertCount(1, $result->signals());
        $this->assertSame('new_relic:live', $result->signals()[0]->id->value);
    }

    public function test_falls_back_to_fixtures_when_credentials_are_absent(): void
    {
        $client = new StubNewRelicClient(SourceFetchResult::fromSignals([$this->liveSignal()]));
        $adapter = new NewRelicAdapter(client: $client);

        $result = $adapter->fetch($this->sourceConfig('new_relic'), $this->timeWindow());

        $this->assertSame(0, $client->calls);
        $this->assertCount(3, $result->signals());
    }

    public function test_uses_fixtures_when_no_credentialed_client_is_injected(): void
    {
        $adapter = new NewRelicAdapter();

        $result = $adapter->fetch($this->credentialedConfig(), $this->timeWindow());

        $this->assertCount(3, $result->signals());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function sourceConfig(string $name, array $options = []): SourceConfig
    {
        return new SourceConfig(
            name: $name,
            enabled: true,
            options: $options,
            credentials: new SourceCredentials(),
        );
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

    private function liveSignal(): Signal
    {
        return new Signal(
            id: new SignalId('new_relic:live'),
            source: new SignalSource('new_relic'),
            type: new SignalType('latency_spike'),
            timestamp: new DateTimeImmutable('2026-06-08T12:10:00+00:00'),
            severity: SignalSeverity::warning(),
            entityReferences: [new EntityReference('service', 'checkout-api')],
            attributes: ['duration_ms' => 1000],
            rawPayloadReference: new RawPayloadReference('live'),
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

final class StubNewRelicClient implements NewRelicClient
{
    public int $calls = 0;

    public function __construct(private readonly SourceFetchResult $result)
    {
    }

    public function fetch(SourceConfig $config, TimeWindow $window): SourceFetchResult
    {
        $this->calls++;

        return $this->result;
    }
}
