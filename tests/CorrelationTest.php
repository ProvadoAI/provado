<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Mquevedob\Provado\Core\EntityReference;
use Mquevedob\Provado\Core\RawPayloadReference;
use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Core\SignalId;
use Mquevedob\Provado\Core\SignalSeverity;
use Mquevedob\Provado\Core\SignalSource;
use Mquevedob\Provado\Core\SignalType;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Correlation\CorrelationCriteria;
use Mquevedob\Provado\Correlation\CorrelationEngine;
use Mquevedob\Provado\Correlation\CorrelationGroup;
use Mquevedob\Provado\Storage\InMemorySignalStore;
use PHPUnit\Framework\TestCase;

class CorrelationTest extends TestCase
{
    public function test_grouping_signals_sharing_an_entity(): void
    {
        $first = $this->signal('signal-1', entityReferences: [new EntityReference('service', 'checkout')]);
        $second = $this->signal('signal-2', entityReferences: [new EntityReference('service', 'checkout')]);
        $engine = new CorrelationEngine($this->storeWithSignals([$first, $second]));

        $groups = $engine->correlate($this->window());

        $this->assertCount(1, $groups);
        $this->assertSame(['signal-1', 'signal-2'], $this->signalIds($groups[0]->signals));
    }

    public function test_not_grouping_unrelated_signals(): void
    {
        $first = $this->signal('signal-1', entityReferences: [new EntityReference('service', 'checkout')]);
        $second = $this->signal('signal-2', entityReferences: [new EntityReference('service', 'catalog')]);
        $engine = new CorrelationEngine($this->storeWithSignals([$first, $second]));

        $this->assertSame([], $engine->correlate($this->window()));
    }

    public function test_grouping_only_signals_inside_time_window(): void
    {
        $insideFirst = $this->signal('signal-1', timestamp: new DateTimeImmutable('2026-06-08T12:00:00+00:00'));
        $insideSecond = $this->signal('signal-2', timestamp: new DateTimeImmutable('2026-06-08T12:05:00+00:00'));
        $outside = $this->signal('signal-3', timestamp: new DateTimeImmutable('2026-06-08T12:06:00+00:00'));
        $engine = new CorrelationEngine($this->storeWithSignals([$insideFirst, $insideSecond, $outside]));

        $groups = $engine->correlate(new TimeWindow(
            new DateTimeImmutable('2026-06-08T12:00:00+00:00'),
            new DateTimeImmutable('2026-06-08T12:05:00+00:00'),
        ));

        $this->assertCount(1, $groups);
        $this->assertSame(['signal-1', 'signal-2'], $this->signalIds($groups[0]->signals));
    }

    public function test_deterministic_correlation_id_regardless_of_signal_order(): void
    {
        $first = $this->signal('signal-1');
        $second = $this->signal('signal-2');

        $forward = new CorrelationGroup([$first, $second]);
        $reverse = new CorrelationGroup([$second, $first]);

        $this->assertTrue($forward->id->equals($reverse->id));
    }

    public function test_shared_entities_calculation(): void
    {
        $shared = new EntityReference('service', 'checkout');
        $first = $this->signal('signal-1', entityReferences: [$shared, new EntityReference('store', 'default')]);
        $second = $this->signal('signal-2', entityReferences: [$shared, new EntityReference('queue', 'orders')]);
        $group = new CorrelationGroup([$first, $second]);

        $this->assertSame(['service:checkout'], $this->entityKeys($group->sharedEntities()));
    }

    public function test_involved_sources_and_types_calculation(): void
    {
        $first = $this->signal(
            'signal-1',
            source: new SignalSource('new_relic'),
            type: new SignalType('latency_spike'),
        );
        $second = $this->signal(
            'signal-2',
            source: new SignalSource('adobe_commerce'),
            type: new SignalType('checkout_failure_rate'),
        );
        $group = new CorrelationGroup([$first, $second]);

        $this->assertSame(['new_relic', 'adobe_commerce'], $this->sourceValues($group->involvedSources()));
        $this->assertSame(['latency_spike', 'checkout_failure_rate'], $this->typeValues($group->involvedTypes()));
    }

    public function test_highest_severity_calculation(): void
    {
        $group = new CorrelationGroup([
            $this->signal('signal-1', severity: SignalSeverity::warning()),
            $this->signal('signal-2', severity: SignalSeverity::critical()),
            $this->signal('signal-3', severity: SignalSeverity::error()),
        ]);

        $this->assertTrue(SignalSeverity::critical()->equals($group->highestSeverity()));
    }

    public function test_start_and_end_timestamp_calculation(): void
    {
        $group = new CorrelationGroup([
            $this->signal('signal-1', timestamp: new DateTimeImmutable('2026-06-08T12:05:00+00:00')),
            $this->signal('signal-2', timestamp: new DateTimeImmutable('2026-06-08T12:00:00+00:00')),
            $this->signal('signal-3', timestamp: new DateTimeImmutable('2026-06-08T12:10:00+00:00')),
        ]);

        $this->assertEquals(new DateTimeImmutable('2026-06-08T12:00:00+00:00'), $group->startsAt());
        $this->assertEquals(new DateTimeImmutable('2026-06-08T12:10:00+00:00'), $group->endsAt());
    }

    public function test_criteria_filters_by_source_type_and_severity(): void
    {
        $matchingFirst = $this->signal(
            'signal-1',
            source: new SignalSource('new_relic'),
            type: new SignalType('latency_spike'),
            severity: SignalSeverity::critical(),
        );
        $matchingSecond = $this->signal(
            'signal-2',
            source: new SignalSource('new_relic'),
            type: new SignalType('latency_spike'),
            severity: SignalSeverity::critical(),
        );
        $wrongSource = $this->signal('signal-3', source: new SignalSource('adobe_commerce'), type: new SignalType('latency_spike'), severity: SignalSeverity::critical());
        $wrongType = $this->signal('signal-4', source: new SignalSource('new_relic'), type: new SignalType('error_rate_spike'), severity: SignalSeverity::critical());
        $wrongSeverity = $this->signal('signal-5', source: new SignalSource('new_relic'), type: new SignalType('latency_spike'), severity: SignalSeverity::warning());
        $engine = new CorrelationEngine($this->storeWithSignals([
            $matchingFirst,
            $matchingSecond,
            $wrongSource,
            $wrongType,
            $wrongSeverity,
        ]));

        $groups = $engine->correlate(
            $this->window(),
            CorrelationCriteria::all()
                ->withSource('new_relic')
                ->withType('latency_spike')
                ->withSeverity('critical'),
        );

        $this->assertCount(1, $groups);
        $this->assertSame(['signal-1', 'signal-2'], $this->signalIds($groups[0]->signals));
    }

    public function test_correlation_criteria_can_provide_time_window(): void
    {
        $insideFirst = $this->signal('signal-1', timestamp: new DateTimeImmutable('2026-06-08T12:02:00+00:00'));
        $insideSecond = $this->signal('signal-2', timestamp: new DateTimeImmutable('2026-06-08T12:03:00+00:00'));
        $outside = $this->signal('signal-3', timestamp: new DateTimeImmutable('2026-06-08T12:10:00+00:00'));
        $engine = new CorrelationEngine($this->storeWithSignals([$insideFirst, $insideSecond, $outside]));

        $groups = $engine->correlate(
            new TimeWindow(new DateTimeImmutable('2026-06-08T12:00:00+00:00'), new DateTimeImmutable('2026-06-08T12:10:00+00:00')),
            CorrelationCriteria::all()->within(new TimeWindow(
                new DateTimeImmutable('2026-06-08T12:01:00+00:00'),
                new DateTimeImmutable('2026-06-08T12:04:00+00:00'),
            )),
        );

        $this->assertCount(1, $groups);
        $this->assertSame(['signal-1', 'signal-2'], $this->signalIds($groups[0]->signals));
    }

    public function test_criteria_window_wider_than_method_window_does_not_expand_correlation(): void
    {
        $insideFirst = $this->signal('signal-1', timestamp: new DateTimeImmutable('2026-06-08T12:02:00+00:00'));
        $insideSecond = $this->signal('signal-2', timestamp: new DateTimeImmutable('2026-06-08T12:03:00+00:00'));
        $outside = $this->signal('signal-3', timestamp: new DateTimeImmutable('2026-06-08T12:08:00+00:00'));
        $engine = new CorrelationEngine($this->storeWithSignals([$insideFirst, $insideSecond, $outside]));

        $groups = $engine->correlate(
            new TimeWindow(new DateTimeImmutable('2026-06-08T12:00:00+00:00'), new DateTimeImmutable('2026-06-08T12:05:00+00:00')),
            CorrelationCriteria::all()->within(new TimeWindow(
                new DateTimeImmutable('2026-06-08T11:55:00+00:00'),
                new DateTimeImmutable('2026-06-08T12:10:00+00:00'),
            )),
        );

        $this->assertCount(1, $groups);
        $this->assertSame(['signal-1', 'signal-2'], $this->signalIds($groups[0]->signals));
    }

    public function test_non_overlapping_method_and_criteria_windows_return_no_groups(): void
    {
        $first = $this->signal('signal-1', timestamp: new DateTimeImmutable('2026-06-08T12:00:00+00:00'));
        $second = $this->signal('signal-2', timestamp: new DateTimeImmutable('2026-06-08T12:01:00+00:00'));
        $engine = new CorrelationEngine($this->storeWithSignals([$first, $second]));

        $groups = $engine->correlate(
            new TimeWindow(new DateTimeImmutable('2026-06-08T12:00:00+00:00'), new DateTimeImmutable('2026-06-08T12:05:00+00:00')),
            CorrelationCriteria::all()->within(new TimeWindow(
                new DateTimeImmutable('2026-06-08T12:06:00+00:00'),
                new DateTimeImmutable('2026-06-08T12:10:00+00:00'),
            )),
        );

        $this->assertSame([], $groups);
    }

    public function test_time_proximity_joins_entity_disjoint_signals_within_threshold(): void
    {
        $first = $this->signal('signal-1', entityReferences: [new EntityReference('service', 'checkout')], timestamp: new DateTimeImmutable('2026-06-08T12:00:00+00:00'));
        $second = $this->signal('signal-2', entityReferences: [new EntityReference('service', 'catalog')], timestamp: new DateTimeImmutable('2026-06-08T12:01:00+00:00'));
        $engine = new CorrelationEngine($this->storeWithSignals([$first, $second]));

        $groups = $engine->correlate(
            $this->window(),
            CorrelationCriteria::all()->withTimeProximity(120),
        );

        $this->assertCount(1, $groups);
        $this->assertSame(['signal-1', 'signal-2'], $this->signalIds($groups[0]->signals));
    }

    public function test_time_proximity_does_not_join_signals_beyond_threshold(): void
    {
        $first = $this->signal('signal-1', entityReferences: [new EntityReference('service', 'checkout')], timestamp: new DateTimeImmutable('2026-06-08T12:00:00+00:00'));
        $second = $this->signal('signal-2', entityReferences: [new EntityReference('service', 'catalog')], timestamp: new DateTimeImmutable('2026-06-08T12:05:00+00:00'));
        $engine = new CorrelationEngine($this->storeWithSignals([$first, $second]));

        $groups = $engine->correlate(
            $this->window(),
            CorrelationCriteria::all()->withTimeProximity(120),
        );

        $this->assertSame([], $groups);
    }

    public function test_shared_entity_join_still_applies_when_time_proximity_is_enabled(): void
    {
        $shared = new EntityReference('service', 'checkout');
        $first = $this->signal('signal-1', entityReferences: [$shared], timestamp: new DateTimeImmutable('2026-06-08T12:00:00+00:00'));
        $second = $this->signal('signal-2', entityReferences: [$shared], timestamp: new DateTimeImmutable('2026-06-08T12:05:00+00:00'));
        $engine = new CorrelationEngine($this->storeWithSignals([$first, $second]));

        $groups = $engine->correlate(
            $this->window(),
            CorrelationCriteria::all()->withTimeProximity(60),
        );

        $this->assertCount(1, $groups);
        $this->assertSame(['signal-1', 'signal-2'], $this->signalIds($groups[0]->signals));
    }

    public function test_time_proximity_is_disabled_by_default(): void
    {
        $first = $this->signal('signal-1', entityReferences: [new EntityReference('service', 'checkout')], timestamp: new DateTimeImmutable('2026-06-08T12:00:00+00:00'));
        $second = $this->signal('signal-2', entityReferences: [new EntityReference('service', 'catalog')], timestamp: new DateTimeImmutable('2026-06-08T12:00:30+00:00'));
        $engine = new CorrelationEngine($this->storeWithSignals([$first, $second]));

        $this->assertSame([], $engine->correlate($this->window()));
    }

    public function test_time_proximity_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Correlation time proximity must be greater than zero seconds.');

        CorrelationCriteria::all()->withTimeProximity(0);
    }

    public function test_empty_signal_group_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CorrelationGroup requires at least one signal.');

        new CorrelationGroup([]);
    }

    /**
     * @param list<Signal> $signals
     */
    private function storeWithSignals(array $signals): InMemorySignalStore
    {
        $store = new InMemorySignalStore();
        $store->saveMany($signals);

        return $store;
    }

    private function window(): TimeWindow
    {
        return new TimeWindow(
            new DateTimeImmutable('2026-06-08T11:59:00+00:00'),
            new DateTimeImmutable('2026-06-08T12:06:00+00:00'),
        );
    }

    /**
     * @param list<Signal> $signals
     * @return list<string>
     */
    private function signalIds(array $signals): array
    {
        return array_map(
            static fn (Signal $signal): string => $signal->id->value,
            $signals,
        );
    }

    /**
     * @param list<EntityReference> $entities
     * @return list<string>
     */
    private function entityKeys(array $entities): array
    {
        return array_map(
            static fn (EntityReference $entityReference): string => $entityReference->type.':'.$entityReference->id,
            $entities,
        );
    }

    /**
     * @param list<SignalSource> $sources
     * @return list<string>
     */
    private function sourceValues(array $sources): array
    {
        return array_map(
            static fn (SignalSource $source): string => $source->value,
            $sources,
        );
    }

    /**
     * @param list<SignalType> $types
     * @return list<string>
     */
    private function typeValues(array $types): array
    {
        return array_map(
            static fn (SignalType $type): string => $type->value,
            $types,
        );
    }

    /**
     * @param list<EntityReference>|null $entityReferences
     */
    private function signal(
        string $id,
        ?SignalSource $source = null,
        ?SignalType $type = null,
        ?DateTimeImmutable $timestamp = null,
        ?SignalSeverity $severity = null,
        ?array $entityReferences = null,
    ): Signal {
        return new Signal(
            id: new SignalId($id),
            source: $source ?? new SignalSource('new_relic'),
            type: $type ?? new SignalType('latency_spike'),
            timestamp: $timestamp ?? new DateTimeImmutable('2026-06-08T12:00:00+00:00'),
            severity: $severity ?? SignalSeverity::warning(),
            entityReferences: $entityReferences ?? [new EntityReference('service', 'checkout')],
            attributes: ['duration_ms' => 1250],
            rawPayloadReference: new RawPayloadReference('payload-'.$id),
        );
    }
}
