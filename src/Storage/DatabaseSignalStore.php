<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Storage;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use Mquevedob\Provado\Core\EntityReference;
use Mquevedob\Provado\Core\RawPayloadReference;
use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Core\SignalId;
use Mquevedob\Provado\Core\SignalSeverity;
use Mquevedob\Provado\Core\SignalSource;
use Mquevedob\Provado\Core\SignalType;

/**
 * Database-backed signal store. Every store instance is scoped to a single
 * run id: rows persist in a shared table but reads only ever see this run's
 * signals, so within a run it behaves exactly like InMemorySignalStore while
 * surviving beyond the process. The matching SignalStoreFactory mints a fresh
 * run id per create() call.
 */
final class DatabaseSignalStore implements SignalStore
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $runId,
        private readonly string $table = 'provado_signals',
    ) {
    }

    public function save(Signal $signal): void
    {
        $this->rows()->updateOrInsert(
            ['run_id' => $this->runId, 'signal_id' => $signal->id->value],
            $this->encode($signal),
        );
    }

    public function saveMany(array $signals): void
    {
        // Validate the whole batch before writing anything so a bad entry
        // never leaves a partial save behind (parity with InMemorySignalStore).
        foreach ($signals as $signal) {
            if (! $signal instanceof Signal) {
                throw new InvalidArgumentException('SignalStore::saveMany expects only Signal instances.');
            }
        }

        // Persist the batch atomically so a write failure never leaves a
        // partial save behind (parity with InMemorySignalStore).
        $this->connection->transaction(function () use ($signals): void {
            foreach ($signals as $signal) {
                $this->save($signal);
            }
        });
    }

    public function all(): array
    {
        return $this->hydrateAll($this->rows()->orderBy('id')->get()->all());
    }

    public function query(SignalQuery $query): array
    {
        $builder = $this->rows();

        // Push the exact scalar equality filters down to SQL; window and entity
        // matching is finished in PHP via SignalQuery::matches() so results are
        // identical to InMemorySignalStore.
        if ($query->source !== null) {
            $builder->where('source', $query->source->value);
        }

        if ($query->type !== null) {
            $builder->where('type', $query->type->value);
        }

        if ($query->severity !== null) {
            $builder->where('severity', $query->severity->value);
        }

        $matches = [];

        foreach ($builder->orderBy('id')->get()->all() as $row) {
            $signal = $this->hydrate($row);

            if ($query->matches($signal)) {
                $matches[] = $signal;
            }
        }

        return $matches;
    }

    public function findById(SignalId $id): ?Signal
    {
        $row = $this->rows()->where('signal_id', $id->value)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    private function rows(): Builder
    {
        return $this->connection->table($this->table)->where('run_id', $this->runId);
    }

    /**
     * @return array<string, mixed>
     */
    private function encode(Signal $signal): array
    {
        $entityReferences = [];

        foreach ($signal->entityReferences as $entityReference) {
            $entityReferences[] = [
                'type' => $entityReference->type,
                'id' => $entityReference->id,
            ];
        }

        return [
            'run_id' => $this->runId,
            'signal_id' => $signal->id->value,
            'source' => $signal->source->value,
            'type' => $signal->type->value,
            'severity' => $signal->severity->value,
            // Keep microsecond precision so the hydrated timestamp matches the
            // original instant exactly (DATE_ATOM would truncate sub-seconds).
            'occurred_at' => $signal->timestamp->format('Y-m-d\TH:i:s.uP'),
            'entity_references' => json_encode($entityReferences, JSON_THROW_ON_ERROR),
            // PRESERVE_ZERO_FRACTION keeps whole-number floats (2.0) from
            // degrading to ints on round-trip.
            'attributes' => json_encode($signal->attributes, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            'raw_payload_id' => $signal->rawPayloadReference->id,
            'raw_payload_location' => $signal->rawPayloadReference->location,
        ];
    }

    /**
     * @param list<object> $rows
     * @return list<Signal>
     */
    private function hydrateAll(array $rows): array
    {
        return array_map(fn (object $row): Signal => $this->hydrate($row), $rows);
    }

    private function hydrate(object $row): Signal
    {
        /** @var list<array{type: string, id: string}> $entityReferenceData */
        $entityReferenceData = (array) json_decode((string) $row->entity_references, true, 512, JSON_THROW_ON_ERROR);

        $entityReferences = [];

        foreach ($entityReferenceData as $entityReference) {
            $entityReferences[] = new EntityReference(
                (string) $entityReference['type'],
                (string) $entityReference['id'],
            );
        }

        /** @var array<string, mixed> $attributes */
        $attributes = (array) json_decode((string) $row->attributes, true, 512, JSON_THROW_ON_ERROR);

        return new Signal(
            id: new SignalId((string) $row->signal_id),
            source: new SignalSource((string) $row->source),
            type: new SignalType((string) $row->type),
            timestamp: new DateTimeImmutable((string) $row->occurred_at),
            severity: new SignalSeverity((string) $row->severity),
            entityReferences: $entityReferences,
            attributes: $attributes,
            rawPayloadReference: new RawPayloadReference(
                (string) $row->raw_payload_id,
                $row->raw_payload_location !== null ? (string) $row->raw_payload_location : null,
            ),
        );
    }
}
