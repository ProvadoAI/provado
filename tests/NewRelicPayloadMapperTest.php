<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Mquevedob\Provado\Core\EntityReference;
use Mquevedob\Provado\Core\SignalType;
use Mquevedob\Provado\Sources\NewRelic\NewRelicPayloadMapper;
use PHPUnit\Framework\TestCase;

class NewRelicPayloadMapperTest extends TestCase
{
    public function test_store_entity_is_added_when_present(): void
    {
        $signal = (new NewRelicPayloadMapper())->map($this->payload([
            'entities' => ['store' => 'default'],
        ]));

        $this->assertTrue($signal->hasEntity(new EntityReference('store', 'default')));
        $this->assertTrue($signal->hasEntity(new EntityReference('service', 'checkout-api')));
    }

    public function test_signal_maps_without_store_when_entities_block_is_absent(): void
    {
        $signal = (new NewRelicPayloadMapper())->map($this->payload());

        $this->assertTrue($signal->hasEntity(new EntityReference('service', 'checkout-api')));
        $this->assertFalse($signal->hasEntity(new EntityReference('store', 'default')));
    }

    public function test_entities_block_without_store_does_not_fail_mapping(): void
    {
        $signal = (new NewRelicPayloadMapper())->map($this->payload([
            'entities' => ['host' => 'web-1'],
        ]));

        // The signal still maps; the unrelated entity simply yields no store hint.
        $this->assertTrue($signal->hasEntity(new EntityReference('service', 'checkout-api')));
        $this->assertFalse($signal->hasEntity(new EntityReference('store', 'web-1')));
    }

    public function test_blank_store_value_is_skipped(): void
    {
        $signal = (new NewRelicPayloadMapper())->map($this->payload([
            'entities' => ['store' => '  '],
        ]));

        $this->assertTrue($signal->hasEntity(new EntityReference('service', 'checkout-api')));

        $storeReferences = array_filter(
            $signal->entityReferences,
            static fn (EntityReference $reference): bool => $reference->type === 'store',
        );
        $this->assertSame([], $storeReferences);
    }

    public function test_nrql_row_maps_facets_positionally_and_collects_numeric_attributes(): void
    {
        $signal = (new NewRelicPayloadMapper())->mapNrqlRow(
            [
                'facet' => ['Magento Lab - Provado', 'OtherTransaction/Custom/CLI cron:run'],
                'throughput' => 289,
                'duration_ms' => 6.85,
                'error_rate' => 0,
            ],
            new SignalType('transaction_health'),
            new DateTimeImmutable('2026-06-08T12:30:00+00:00'),
            ['service', 'transaction'],
            'nrql:0',
        );

        $this->assertSame('new_relic:nrql:0', $signal->id->value);
        $this->assertSame('info', $signal->severity->value);
        $this->assertTrue($signal->hasEntity(new EntityReference('service', 'Magento Lab - Provado')));
        $this->assertTrue($signal->hasEntity(new EntityReference('transaction', 'OtherTransaction/Custom/CLI cron:run')));
        $this->assertSame(['throughput' => 289, 'duration_ms' => 6.85, 'error_rate' => 0], $signal->attributes);
        $this->assertNull($signal->rawPayloadReference->location);
    }

    public function test_nrql_row_honors_explicit_severity_column(): void
    {
        $signal = (new NewRelicPayloadMapper())->mapNrqlRow(
            ['facet' => 'checkout-api', 'severity' => 'critical', 'error_rate' => 0.4],
            new SignalType('transaction_health'),
            new DateTimeImmutable('2026-06-08T12:30:00+00:00'),
            ['service'],
            'nrql:0',
        );

        // A single-facet scalar is normalized to one positional entity, and the
        // severity column is consumed rather than treated as an attribute.
        $this->assertSame('critical', $signal->severity->value);
        $this->assertTrue($signal->hasEntity(new EntityReference('service', 'checkout-api')));
        $this->assertSame(['error_rate' => 0.4], $signal->attributes);
    }

    public function test_nrql_row_without_resolvable_facet_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new NewRelicPayloadMapper())->mapNrqlRow(
            ['throughput' => 10],
            new SignalType('transaction_health'),
            new DateTimeImmutable('2026-06-08T12:30:00+00:00'),
            ['service'],
            'nrql:0',
        );
    }

    public function test_nrql_row_without_numeric_attributes_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new NewRelicPayloadMapper())->mapNrqlRow(
            ['facet' => ['checkout-api']],
            new SignalType('transaction_health'),
            new DateTimeImmutable('2026-06-08T12:30:00+00:00'),
            ['service'],
            'nrql:0',
        );
    }

    public function test_provado_signal_event_maps_to_canonical_signal(): void
    {
        $signal = (new NewRelicPayloadMapper())->mapProvadoSignalEvent(
            [
                'signal' => 'cron_health',
                'source' => 'magento',
                'store' => 'default',
                'missed' => 3,
                'pending' => 12,
                'error' => 0,
                'timestamp' => 1_700_000_000_000,
            ],
            ['store', 'indexer', 'queue', 'cron_job'],
            0,
            new DateTimeImmutable('2026-06-08T12:30:00+00:00'),
        );

        $this->assertSame('magento:cron_health:0', $signal->id->value);
        $this->assertSame('magento', $signal->source->value);
        $this->assertSame('cron_health', $signal->type->value);
        $this->assertSame('info', $signal->severity->value);
        $this->assertTrue($signal->hasEntity(new EntityReference('store', 'default')));
        $this->assertSame(['missed' => 3, 'pending' => 12, 'error' => 0], $signal->attributes);
        // signal/source/timestamp/entity attributes are not metrics.
        $this->assertArrayNotHasKey('timestamp', $signal->attributes);
        $this->assertSame('2023-11-14', $signal->timestamp->format('Y-m-d'));
    }

    public function test_cache_validity_event_maps_the_cache_type_to_an_entity(): void
    {
        // The Instrument shipper emits one cache_validity event per cache type with a
        // 0/1 invalidated flag; the reader must treat `cache` as an entity dimension
        // so each type is its own series (not collapsed under the source fallback).
        $signal = (new NewRelicPayloadMapper())->mapProvadoSignalEvent(
            [
                'signal' => 'cache_validity',
                'source' => 'magento',
                'cache' => 'full_page',
                'invalidated' => 1,
                'timestamp' => 1_700_000_000_000,
            ],
            ['store', 'indexer', 'queue', 'cache', 'cron_job', 'host'],
            0,
            new DateTimeImmutable('2026-06-08T12:30:00+00:00'),
        );

        $this->assertTrue($signal->hasEntity(new EntityReference('cache', 'full_page')));
        $this->assertSame(['invalidated' => 1], $signal->attributes);
        // The cache type name is an entity, not a metric.
        $this->assertArrayNotHasKey('cache', $signal->attributes);
    }

    public function test_provado_signal_event_excludes_new_relic_internal_attributes(): void
    {
        $signal = (new NewRelicPayloadMapper())->mapProvadoSignalEvent(
            [
                'signal' => 'cron_health',
                'source' => 'magento',
                'host' => 'provado',
                'error' => 2865,
                'pending' => 391,
                // New Relic agent auto-adds these to custom events.
                'appId' => 999121426,
                'realAgentId' => 999122235,
            ],
            ['store', 'host', 'cron_job'],
            0,
            new DateTimeImmutable('2026-06-08T12:30:00+00:00'),
        );

        $this->assertTrue($signal->hasEntity(new EntityReference('host', 'provado')));
        $this->assertSame(['error' => 2865, 'pending' => 391], $signal->attributes);
        $this->assertArrayNotHasKey('appId', $signal->attributes);
        $this->assertArrayNotHasKey('realAgentId', $signal->attributes);
    }

    public function test_provado_signal_event_falls_back_to_source_entity(): void
    {
        $signal = (new NewRelicPayloadMapper())->mapProvadoSignalEvent(
            ['signal' => 'queue_backlog', 'source' => 'magento', 'ready' => 40],
            ['store', 'queue'],
            1,
            new DateTimeImmutable('2026-06-08T12:30:00+00:00'),
        );

        // No entity attribute present → falls back to the source so the ≥1 entity holds.
        $this->assertTrue($signal->hasEntity(new EntityReference('source', 'magento')));
        $this->assertSame(40, $signal->attributes['ready']);
    }

    public function test_provado_signal_event_without_signal_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new NewRelicPayloadMapper())->mapProvadoSignalEvent(
            ['source' => 'magento', 'missed' => 1],
            ['store'],
            0,
            new DateTimeImmutable('2026-06-08T12:30:00+00:00'),
        );
    }

    public function test_provado_signal_event_without_metrics_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new NewRelicPayloadMapper())->mapProvadoSignalEvent(
            ['signal' => 'cron_health', 'source' => 'magento', 'store' => 'default'],
            ['store'],
            0,
            new DateTimeImmutable('2026-06-08T12:30:00+00:00'),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'id' => 'latency_spike',
            'event_type' => 'latency_spike',
            'timestamp' => '2026-06-08T12:00:00+00:00',
            'severity' => 'warning',
            'application' => ['name' => 'checkout-api'],
            'metrics' => ['duration_ms' => 2450],
            ...$overrides,
        ];
    }
}
