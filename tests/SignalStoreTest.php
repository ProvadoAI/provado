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
use Mquevedob\Provado\Storage\InMemorySignalStore;
use Mquevedob\Provado\Storage\SignalQuery;
use PHPUnit\Framework\TestCase;
use stdClass;

class SignalStoreTest extends TestCase
{
    public function test_saving_one_signal(): void
    {
        $store = new InMemorySignalStore();
        $signal = $this->signal('signal-1');

        $store->save($signal);

        $this->assertSame([$signal], $store->all());
    }

    public function test_saving_many_signals(): void
    {
        $store = new InMemorySignalStore();
        $first = $this->signal('signal-1');
        $second = $this->signal('signal-2');

        $store->saveMany([$first, $second]);

        $this->assertSame([$first, $second], $store->all());
    }

    public function test_replacing_signal_with_same_id(): void
    {
        $store = new InMemorySignalStore();
        $original = $this->signal('signal-1', severity: SignalSeverity::warning());
        $replacement = $this->signal('signal-1', severity: SignalSeverity::critical());

        $store->save($original);
        $store->save($replacement);

        $this->assertSame([$replacement], $store->all());
        $this->assertSame($replacement, $store->findById(new SignalId('signal-1')));
    }

    public function test_find_by_id(): void
    {
        $store = new InMemorySignalStore();
        $signal = $this->signal('signal-1');

        $store->save($signal);

        $this->assertSame($signal, $store->findById(new SignalId('signal-1')));
        $this->assertNull($store->findById(new SignalId('missing-signal')));
    }

    public function test_querying_by_source(): void
    {
        $store = $this->storeWithSignals([
            $this->signal('signal-1', source: new SignalSource('new_relic')),
            $this->signal('signal-2', source: new SignalSource('adobe_commerce')),
        ]);

        $this->assertSame(
            ['signal-1'],
            $this->ids($store->query(SignalQuery::all()->withSource('new_relic'))),
        );
    }

    public function test_querying_by_type(): void
    {
        $store = $this->storeWithSignals([
            $this->signal('signal-1', type: new SignalType('latency_spike')),
            $this->signal('signal-2', type: new SignalType('checkout_failure_rate')),
        ]);

        $this->assertSame(
            ['signal-2'],
            $this->ids($store->query(SignalQuery::all()->withType('checkout_failure_rate'))),
        );
    }

    public function test_querying_by_severity(): void
    {
        $store = $this->storeWithSignals([
            $this->signal('signal-1', severity: SignalSeverity::warning()),
            $this->signal('signal-2', severity: SignalSeverity::critical()),
        ]);

        $this->assertSame(
            ['signal-2'],
            $this->ids($store->query(SignalQuery::all()->withSeverity('critical'))),
        );
    }

    public function test_querying_by_entity_reference(): void
    {
        $store = $this->storeWithSignals([
            $this->signal('signal-1', entityReferences: [new EntityReference('service', 'checkout')]),
            $this->signal('signal-2', entityReferences: [new EntityReference('store', 'default')]),
        ]);

        $this->assertSame(
            ['signal-1'],
            $this->ids($store->query(SignalQuery::all()->withEntity(new EntityReference('service', 'checkout')))),
        );
    }

    public function test_querying_by_time_window(): void
    {
        $store = $this->storeWithSignals([
            $this->signal('signal-1', timestamp: new DateTimeImmutable('2026-06-08T11:59:00+00:00')),
            $this->signal('signal-2', timestamp: new DateTimeImmutable('2026-06-08T12:00:00+00:00')),
            $this->signal('signal-3', timestamp: new DateTimeImmutable('2026-06-08T12:05:00+00:00')),
        ]);

        $query = SignalQuery::all()->within(new TimeWindow(
            new DateTimeImmutable('2026-06-08T12:00:00+00:00'),
            new DateTimeImmutable('2026-06-08T12:04:59+00:00'),
        ));

        $this->assertSame(['signal-2'], $this->ids($store->query($query)));
    }

    public function test_query_with_multiple_filters(): void
    {
        $store = $this->storeWithSignals([
            $this->signal('signal-1', source: new SignalSource('new_relic'), type: new SignalType('latency_spike'), severity: SignalSeverity::warning(), entityReferences: [new EntityReference('service', 'checkout')], timestamp: new DateTimeImmutable('2026-06-08T12:01:00+00:00')),
            $this->signal('signal-2', source: new SignalSource('new_relic'), type: new SignalType('latency_spike'), severity: SignalSeverity::critical(), entityReferences: [new EntityReference('service', 'checkout')], timestamp: new DateTimeImmutable('2026-06-08T12:01:00+00:00')),
            $this->signal('signal-3', source: new SignalSource('adobe_commerce'), type: new SignalType('latency_spike'), severity: SignalSeverity::warning(), entityReferences: [new EntityReference('service', 'checkout')], timestamp: new DateTimeImmutable('2026-06-08T12:01:00+00:00')),
        ]);

        $query = SignalQuery::all()
            ->withSource(new SignalSource('new_relic'))
            ->withType(new SignalType('latency_spike'))
            ->withSeverity(SignalSeverity::warning())
            ->withEntity(new EntityReference('service', 'checkout'))
            ->within(new TimeWindow(
                new DateTimeImmutable('2026-06-08T12:00:00+00:00'),
                new DateTimeImmutable('2026-06-08T12:02:00+00:00'),
            ));

        $this->assertSame(['signal-1'], $this->ids($store->query($query)));
    }

    public function test_save_many_rejects_non_signal_entries(): void
    {
        $store = new InMemorySignalStore();
        $signal = $this->signal('signal-1');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SignalStore::saveMany expects only Signal instances.');

        $store->saveMany([$signal, new stdClass()]);
    }

    public function test_save_many_rejects_non_signal_entries_without_partially_saving(): void
    {
        $store = new InMemorySignalStore();

        try {
            $store->saveMany([$this->signal('signal-1'), new stdClass()]);
        } catch (InvalidArgumentException) {
            $this->assertSame([], $store->all());

            return;
        }

        $this->fail('Expected saveMany to reject non-Signal entries.');
    }

    public function test_all_and_query_results_do_not_expose_internal_arrays_by_reference(): void
    {
        $store = new InMemorySignalStore();
        $first = $this->signal('signal-1');
        $second = $this->signal('signal-2');
        $replacement = $this->signal('signal-3');

        $store->saveMany([$first, $second]);

        $all = $store->all();
        $all[0] = $replacement;
        unset($all[1]);

        $queryResults = $store->query(SignalQuery::all());
        $queryResults[0] = $replacement;
        unset($queryResults[1]);

        $this->assertSame([$first, $second], $store->all());
        $this->assertSame([$first, $second], $store->query(SignalQuery::all()));
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

    /**
     * @param list<Signal> $signals
     * @return list<string>
     */
    private function ids(array $signals): array
    {
        return array_map(
            static fn (Signal $signal): string => $signal->id->value,
            $signals,
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
