<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests;

use DateTimeImmutable;
use Mquevedob\Provado\Config\SourceConfig;
use Mquevedob\Provado\Config\SourceCredentials;
use Mquevedob\Provado\Core\EntityReference;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Sources\AdobeCommerce\AdobeCommerceAdapter;
use Mquevedob\Provado\Sources\SourceAdapter;
use Mquevedob\Provado\Sources\SourceFetchResult;
use PHPUnit\Framework\TestCase;

class AdobeCommerceAdapterTest extends TestCase
{
    public function test_adapter_name(): void
    {
        $adapter = new AdobeCommerceAdapter();

        $this->assertInstanceOf(SourceAdapter::class, $adapter);
        $this->assertSame('adobe_commerce', $adapter->name());
    }

    public function test_supports_only_adobe_commerce_source_config(): void
    {
        $adapter = new AdobeCommerceAdapter();

        $this->assertTrue($adapter->supports($this->sourceConfig('adobe_commerce')));
        $this->assertFalse($adapter->supports($this->sourceConfig('new_relic')));
    }

    public function test_fetch_returns_source_fetch_result(): void
    {
        $adapter = new AdobeCommerceAdapter();

        $result = $adapter->fetch($this->sourceConfig('adobe_commerce'), $this->timeWindow());

        $this->assertInstanceOf(SourceFetchResult::class, $result);
        $this->assertCount(4, $result->signals());
        $this->assertSame([], $result->errors());
    }

    public function test_fixture_checkout_failure_rate_payload_maps_to_signal(): void
    {
        $result = (new AdobeCommerceAdapter())->fetch(
            $this->sourceConfig('adobe_commerce', ['fixtures' => ['checkout_failure_rate']]),
            $this->timeWindow(),
        );

        $signal = $result->signals()[0];

        $this->assertSame('adobe_commerce:checkout_failure_rate', $signal->id->value);
        $this->assertSame('adobe_commerce', $signal->source->value);
        $this->assertSame('checkout_failure_rate', $signal->type->value);
        $this->assertSame('error', $signal->severity->value);
        $this->assertTrue($signal->hasEntity(new EntityReference('store', 'default')));
        $this->assertTrue($signal->hasEntity(new EntityReference('checkout', 'onepage')));
        $this->assertSame(0.076, $signal->attributes['failure_rate']);
        $this->assertSame('checkout_failure_rate', $signal->rawPayloadReference->id);
    }

    public function test_fixture_order_sync_backlog_payload_maps_to_signal(): void
    {
        $result = (new AdobeCommerceAdapter())->fetch(
            $this->sourceConfig('adobe_commerce', ['fixtures' => ['order_sync_backlog']]),
            $this->timeWindow(),
        );

        $signal = $result->signals()[0];

        $this->assertSame('adobe_commerce', $signal->source->value);
        $this->assertSame('order_sync_backlog', $signal->type->value);
        $this->assertSame('warning', $signal->severity->value);
        $this->assertTrue($signal->hasEntity(new EntityReference('store', 'default')));
        $this->assertTrue($signal->hasEntity(new EntityReference('queue', 'sales_order_export')));
        $this->assertSame(218, $signal->attributes['backlog_count']);
        $this->assertSame('order_sync_backlog', $signal->rawPayloadReference->id);
    }

    public function test_fixture_inventory_sync_drift_payload_maps_to_signal(): void
    {
        $result = (new AdobeCommerceAdapter())->fetch(
            $this->sourceConfig('adobe_commerce', ['fixtures' => ['inventory_sync_drift']]),
            $this->timeWindow(),
        );

        $signal = $result->signals()[0];

        $this->assertSame('adobe_commerce', $signal->source->value);
        $this->assertSame('inventory_sync_drift', $signal->type->value);
        $this->assertSame('critical', $signal->severity->value);
        $this->assertTrue($signal->hasEntity(new EntityReference('store', 'default')));
        $this->assertTrue($signal->hasEntity(new EntityReference('sku', '24-MB01')));
        $this->assertSame(37, $signal->attributes['drift_count']);
        $this->assertSame('inventory_sync_drift', $signal->rawPayloadReference->id);
    }

    public function test_fixture_indexer_stuck_payload_maps_to_signal(): void
    {
        $result = (new AdobeCommerceAdapter())->fetch(
            $this->sourceConfig('adobe_commerce', ['fixtures' => ['indexer_stuck']]),
            $this->timeWindow(),
        );

        $signal = $result->signals()[0];

        $this->assertSame('adobe_commerce', $signal->source->value);
        $this->assertSame('indexer_stuck', $signal->type->value);
        $this->assertSame('critical', $signal->severity->value);
        $this->assertTrue($signal->hasEntity(new EntityReference('store', 'default')));
        $this->assertTrue($signal->hasEntity(new EntityReference('indexer', 'catalogsearch_fulltext')));
        $this->assertSame(92, $signal->attributes['stuck_duration_minutes']);
        $this->assertSame('indexer_stuck', $signal->rawPayloadReference->id);
    }

    public function test_invalid_fixture_payload_produces_source_fetch_error(): void
    {
        $result = (new AdobeCommerceAdapter())->fetch(
            $this->sourceConfig('adobe_commerce', ['fixtures' => ['invalid_payload']]),
            $this->timeWindow(),
        );

        $this->assertSame([], $result->signals());
        $this->assertTrue($result->hasErrors());
        $this->assertCount(1, $result->errors());
        $this->assertSame('adobe_commerce', $result->errors()[0]->sourceName);
        $this->assertSame('invalid_fixture_payload', $result->errors()[0]->code);
        $this->assertSame('invalid_payload', $result->errors()[0]->context['fixture']);
    }

    public function test_time_window_filtering_excludes_out_of_window_signals(): void
    {
        $result = (new AdobeCommerceAdapter())->fetch(
            $this->sourceConfig('adobe_commerce', ['fixtures' => ['checkout_failure_rate', 'indexer_stuck']]),
            new TimeWindow(
                start: new DateTimeImmutable('2026-06-08T12:00:00+00:00'),
                end: new DateTimeImmutable('2026-06-08T12:05:00+00:00'),
            ),
        );

        $this->assertCount(1, $result->signals());
        $this->assertSame('checkout_failure_rate', $result->signals()[0]->type->value);
        $this->assertSame([], $result->errors());
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

    private function timeWindow(): TimeWindow
    {
        return new TimeWindow(
            start: new DateTimeImmutable('2026-06-08T12:00:00+00:00'),
            end: new DateTimeImmutable('2026-06-08T12:30:00+00:00'),
        );
    }
}
