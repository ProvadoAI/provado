<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests;

use Mquevedob\Provado\Core\EntityReference;
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
