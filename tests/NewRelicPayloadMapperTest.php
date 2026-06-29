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
