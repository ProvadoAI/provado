<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests\Storage;

use DateTimeImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Mquevedob\Provado\Core\EntityReference;
use Mquevedob\Provado\Core\RawPayloadReference;
use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Core\SignalId;
use Mquevedob\Provado\Core\SignalSeverity;
use Mquevedob\Provado\Core\SignalSource;
use Mquevedob\Provado\Core\SignalType;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\ProvadoServiceProvider;
use Mquevedob\Provado\Storage\DatabaseSignalStore;
use Mquevedob\Provado\Storage\DatabaseSignalStoreFactory;
use Mquevedob\Provado\Storage\SignalQuery;
use Mquevedob\Provado\Storage\SignalStore;
use Mquevedob\Provado\Storage\SignalStoreFactory;
use Orchestra\Testbench\TestCase;
use stdClass;

class DatabaseSignalStoreTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param Application $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ProvadoServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('provado.storage.driver', 'database');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }

    public function test_container_resolves_database_factory_and_store(): void
    {
        $factory = $this->app->make(SignalStoreFactory::class);

        $this->assertInstanceOf(DatabaseSignalStoreFactory::class, $factory);
        $this->assertInstanceOf(DatabaseSignalStore::class, $factory->create());
    }

    public function test_saving_and_reading_back_a_signal(): void
    {
        $store = $this->store();
        $signal = $this->signal('signal-1');

        $store->save($signal);

        $this->assertEquals([$signal], $store->all());
        $this->assertEquals($signal, $store->findById(new SignalId('signal-1')));
        $this->assertNull($store->findById(new SignalId('missing')));
    }

    public function test_saving_many_preserves_order(): void
    {
        $store = $this->store();
        $first = $this->signal('signal-1');
        $second = $this->signal('signal-2');

        $store->saveMany([$first, $second]);

        $this->assertSame(['signal-1', 'signal-2'], $this->ids($store->all()));
    }

    public function test_replacing_signal_with_same_id(): void
    {
        $store = $this->store();
        $store->save($this->signal('signal-1', severity: SignalSeverity::warning()));
        $replacement = $this->signal('signal-1', severity: SignalSeverity::critical());

        $store->save($replacement);

        $this->assertEquals([$replacement], $store->all());
        $this->assertEquals($replacement, $store->findById(new SignalId('signal-1')));
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

    public function test_querying_by_type_and_severity(): void
    {
        $store = $this->storeWithSignals([
            $this->signal('signal-1', type: new SignalType('latency_spike'), severity: SignalSeverity::warning()),
            $this->signal('signal-2', type: new SignalType('checkout_failure_rate'), severity: SignalSeverity::critical()),
        ]);

        $this->assertSame(
            ['signal-2'],
            $this->ids($store->query(SignalQuery::all()->withType('checkout_failure_rate'))),
        );
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

    public function test_round_trips_full_signal_fidelity(): void
    {
        $store = $this->store();
        $signal = new Signal(
            id: new SignalId('signal-rich'),
            source: new SignalSource('new_relic'),
            type: new SignalType('latency_spike'),
            timestamp: new DateTimeImmutable('2026-06-08T12:00:00+00:00'),
            severity: SignalSeverity::critical(),
            entityReferences: [new EntityReference('service', 'checkout'), new EntityReference('store', 'default')],
            attributes: ['duration_ms' => 2450, 'error_rate' => 0.084, 'note' => 'slow'],
            rawPayloadReference: new RawPayloadReference('payload-rich', 's3://bucket/payload-rich.json'),
        );

        $store->save($signal);

        $this->assertEquals($signal, $store->findById(new SignalId('signal-rich')));
    }

    public function test_round_trips_microsecond_timestamp_and_preserves_attribute_types(): void
    {
        $store = $this->store();
        $signal = new Signal(
            id: new SignalId('signal-precise'),
            source: new SignalSource('new_relic'),
            type: new SignalType('latency_spike'),
            timestamp: new DateTimeImmutable('2026-06-08T12:00:00.123456+00:00'),
            severity: SignalSeverity::warning(),
            entityReferences: [new EntityReference('service', 'checkout')],
            attributes: ['ratio' => 2.0, 'count' => 3],
            rawPayloadReference: new RawPayloadReference('payload-precise'),
        );

        $store->save($signal);
        $hydrated = $store->findById(new SignalId('signal-precise'));

        $this->assertNotNull($hydrated);
        $this->assertEquals($signal, $hydrated);
        $this->assertSame('2026-06-08T12:00:00.123456+00:00', $hydrated->timestamp->format('Y-m-d\TH:i:s.uP'));
        $this->assertIsFloat($hydrated->attributes['ratio']);
        $this->assertIsInt($hydrated->attributes['count']);
    }

    public function test_save_many_rejects_non_signal_entries_without_partially_saving(): void
    {
        $store = $this->store();

        try {
            $store->saveMany([$this->signal('signal-1'), new stdClass()]);
        } catch (InvalidArgumentException) {
            $this->assertSame([], $store->all());

            return;
        }

        $this->fail('Expected saveMany to reject non-Signal entries.');
    }

    public function test_runs_are_isolated_but_persisted(): void
    {
        $factory = $this->app->make(SignalStoreFactory::class);

        $first = $factory->create();
        $second = $factory->create();

        $first->save($this->signal('signal-1'));

        // A separate run (store) does not see the first run's signals...
        $this->assertSame([], $second->all());
        // ...while the first run still holds its own.
        $this->assertSame(['signal-1'], $this->ids($first->all()));
        // ...and the row is actually persisted in the table.
        $this->assertSame(1, $this->app->make('db')->connection()->table('provado_signals')->count());
    }

    private function store(): SignalStore
    {
        return $this->app->make(SignalStoreFactory::class)->create();
    }

    /**
     * @param list<Signal> $signals
     */
    private function storeWithSignals(array $signals): SignalStore
    {
        $store = $this->store();
        $store->saveMany($signals);

        return $store;
    }

    /**
     * @param list<Signal> $signals
     * @return list<string>
     */
    private function ids(array $signals): array
    {
        return array_map(static fn (Signal $signal): string => $signal->id->value, $signals);
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
            rawPayloadReference: new RawPayloadReference('payload-' . $id),
        );
    }
}
