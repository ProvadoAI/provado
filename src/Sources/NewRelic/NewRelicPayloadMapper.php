<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Sources\NewRelic;

use DateTimeImmutable;
use InvalidArgumentException;
use Mquevedob\Provado\Core\EntityReference;
use Mquevedob\Provado\Core\RawPayloadReference;
use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Core\SignalId;
use Mquevedob\Provado\Core\SignalSeverity;
use Mquevedob\Provado\Core\SignalSource;
use Mquevedob\Provado\Core\SignalType;

final readonly class NewRelicPayloadMapper
{
    /**
     * NRQL columns that are never signal attributes: `facet` holds entity values,
     * `severity` is consumed separately.
     *
     * @var list<string>
     */
    private const NRQL_RESERVED_COLUMNS = ['facet', 'severity'];

    /**
     * @var list<string>
     */
    private const SEVERITY_VALUES = ['info', 'warning', 'error', 'critical'];

    /**
     * Attributes the New Relic agent / platform auto-adds to custom events. They
     * are not shipped metrics and must not leak into a signal's attributes.
     *
     * @var list<string>
     */
    private const NEW_RELIC_RESERVED_ATTRIBUTES = ['appId', 'appName', 'realAgentId', 'entityGuid', 'entity.guid'];

    /**
     * @param array<string, mixed> $payload
     */
    public function map(array $payload, ?string $rawPayloadLocation = null): Signal
    {
        $fixtureId = $this->stringValue($payload, 'id');
        $eventType = $this->stringValue($payload, 'event_type');
        $severity = $this->severity($this->stringValue($payload, 'severity'));
        $timestamp = $this->timestamp($this->stringValue($payload, 'timestamp'));
        $applicationName = $this->nestedStringValue($payload, 'application', 'name');
        $transactionName = $this->optionalNestedStringValue($payload, 'transaction', 'name');
        $storeName = $this->optionalStoreName($payload);
        $metrics = $this->metrics($payload);

        return new Signal(
            id: new SignalId('new_relic:'.$fixtureId),
            source: new SignalSource('new_relic'),
            type: new SignalType($eventType),
            timestamp: $timestamp,
            severity: $severity,
            entityReferences: $this->entityReferences($applicationName, $transactionName, $storeName),
            attributes: $this->attributes($metrics),
            rawPayloadReference: new RawPayloadReference($fixtureId, $rawPayloadLocation),
        );
    }

    /**
     * Map a single live NRQL result row into a canonical Signal.
     *
     * This is the real-response path, kept separate from the fixture-shaped
     * {@see self::map()}. A faceted NRQL row carries its facet values in a
     * `facet` member (an ordered list for multi-facet queries, or a scalar for a
     * single facet) plus numeric aggregate columns — not named entity columns.
     * `$facetEntityTypes` names the canonical entity type for each facet position,
     * matching the order of the query's FACET clause (e.g. `FACET appName, name`
     * → `['service', 'transaction']`).
     *
     * Severity stays `info` unless the row carries an explicit, valid `severity`
     * column: per the architecture direction, a raw signal is an observation and
     * severity *interpretation* belongs to the pattern layer, not the adapter.
     *
     * No NerdGraph envelope shape reaches this method — the client passes a plain
     * result row.
     *
     * @param array<string, mixed> $row
     * @param list<string> $facetEntityTypes canonical entity type per facet position
     */
    public function mapNrqlRow(
        array $row,
        SignalType $type,
        DateTimeImmutable $timestamp,
        array $facetEntityTypes,
        string $id,
    ): Signal {
        $entityReferences = $this->nrqlEntityReferences($row, $facetEntityTypes);

        if ($entityReferences === []) {
            throw new InvalidArgumentException('NRQL result row must include at least one facet entity.');
        }

        $attributes = $this->numericAttributes($row, self::NRQL_RESERVED_COLUMNS);

        if ($attributes === []) {
            throw new InvalidArgumentException('NRQL result row must include at least one numeric attribute.');
        }

        return new Signal(
            id: new SignalId('new_relic:'.$id),
            source: new SignalSource('new_relic'),
            type: $type,
            timestamp: $timestamp,
            severity: $this->nrqlSeverity($row),
            entityReferences: $entityReferences,
            // Live NRQL signals have no on-disk fixture, so no location is recorded.
            rawPayloadReference: new RawPayloadReference($id),
            attributes: $attributes,
        );
    }

    /**
     * Map the NRQL `facet` member to entity references by position. The member is
     * a list for multi-facet queries and a scalar for a single facet; both are
     * normalized to a positional list here.
     *
     * @param array<string, mixed> $row
     * @param list<string> $facetEntityTypes
     * @return list<EntityReference>
     */
    private function nrqlEntityReferences(array $row, array $facetEntityTypes): array
    {
        $facets = $row['facet'] ?? null;

        if (is_string($facets) || is_int($facets) || is_float($facets)) {
            $facets = [$facets];
        }

        if (! is_array($facets)) {
            return [];
        }

        $references = [];

        foreach (array_values($facets) as $position => $value) {
            $entityType = $facetEntityTypes[$position] ?? null;

            if (! is_string($entityType) || trim($entityType) === '') {
                continue;
            }

            if ((is_string($value) || is_int($value) || is_float($value)) && trim((string) $value) !== '') {
                $references[] = new EntityReference($entityType, (string) $value);
            }
        }

        return $references;
    }

    /**
     * Map a `ProvadoSignal` custom event (read from New Relic via NerdGraph) into a
     * canonical Signal. See `docs/signal-shipping.md`: the event is flat — `signal`
     * → type, `source` → source, named entity attributes → entity references,
     * numeric fields → attributes, `timestamp` (epoch ms) → signal timestamp. This
     * is shipper-agnostic: the same shape arrives whether it was shipped by New
     * Relic Flex, a Magento cron + PHP agent, or the Event API.
     *
     * @param array<string, mixed> $event
     * @param list<string> $entityFields attribute names to treat as entity dimensions
     */
    public function mapProvadoSignalEvent(
        array $event,
        array $entityFields,
        int $index,
        DateTimeImmutable $defaultTimestamp,
    ): Signal {
        $signal = $event['signal'] ?? null;

        if (! is_string($signal) || trim($signal) === '') {
            throw new InvalidArgumentException('ProvadoSignal event must include a non-empty "signal".');
        }

        $signal = trim($signal);
        $source = $event['source'] ?? null;
        $sourceValue = is_string($source) && trim($source) !== '' ? trim($source) : 'magento';

        $entityReferences = $this->provadoSignalEntities($event, $entityFields, $sourceValue);
        // The New Relic PHP agent auto-decorates custom events with internal
        // attributes (appId, realAgentId, …); exclude them so only shipped metrics
        // become signal attributes.
        $reserved = array_merge(
            ['signal', 'source', 'timestamp'],
            self::NEW_RELIC_RESERVED_ATTRIBUTES,
            $entityFields,
        );
        $attributes = $this->numericAttributes($event, $reserved);

        if ($attributes === []) {
            throw new InvalidArgumentException('ProvadoSignal event must include at least one numeric metric.');
        }

        $id = $sourceValue.':'.$signal.':'.$index;

        return new Signal(
            id: new SignalId($id),
            source: new SignalSource($sourceValue),
            type: new SignalType($signal),
            timestamp: $this->eventTimestamp($event, $defaultTimestamp),
            severity: SignalSeverity::info(),
            entityReferences: $entityReferences,
            attributes: $attributes,
            rawPayloadReference: new RawPayloadReference($id),
        );
    }

    /**
     * Entity dimensions are named attributes (e.g. store, indexer, queue, cron_job);
     * each present one becomes an EntityReference of that type. Falls back to the
     * source so every signal has the ≥1 entity the canonical model requires.
     *
     * @param array<string, mixed> $event
     * @param list<string> $entityFields
     * @return list<EntityReference>
     */
    private function provadoSignalEntities(array $event, array $entityFields, string $sourceValue): array
    {
        $references = [];

        foreach ($entityFields as $field) {
            $value = $event[$field] ?? null;

            if ((is_string($value) || is_int($value) || is_float($value)) && trim((string) $value) !== '') {
                $references[] = new EntityReference($field, trim((string) $value));
            }
        }

        if ($references === []) {
            $references[] = new EntityReference('source', $sourceValue);
        }

        return $references;
    }

    /**
     * New Relic stamps custom events with an epoch-millisecond `timestamp`.
     *
     * @param array<string, mixed> $event
     */
    private function eventTimestamp(array $event, DateTimeImmutable $default): DateTimeImmutable
    {
        $timestamp = $event['timestamp'] ?? null;

        if (is_int($timestamp) || is_float($timestamp)) {
            return new DateTimeImmutable('@'.intdiv((int) $timestamp, 1000));
        }

        return $default;
    }

    /**
     * Honor an explicit, valid `severity` column; otherwise default to info.
     *
     * @param array<string, mixed> $row
     */
    private function nrqlSeverity(array $row): SignalSeverity
    {
        $severity = $row['severity'] ?? null;

        if (is_string($severity) && in_array(strtolower(trim($severity)), self::SEVERITY_VALUES, true)) {
            return new SignalSeverity(strtolower(trim($severity)));
        }

        return SignalSeverity::info();
    }

    /**
     * @return list<EntityReference>
     */
    private function entityReferences(string $applicationName, ?string $transactionName, ?string $storeName): array
    {
        $references = [new EntityReference('service', $applicationName)];

        if ($transactionName !== null) {
            $references[] = new EntityReference('transaction', $transactionName);
        }

        if ($storeName !== null) {
            $references[] = new EntityReference('store', $storeName);
        }

        return $references;
    }

    /**
     * The store entity is an optional correlation hint. A missing or blank value
     * is skipped rather than failing the whole signal mapping.
     *
     * @param array<string, mixed> $payload
     */
    private function optionalStoreName(array $payload): ?string
    {
        $entities = $payload['entities'] ?? null;

        if (! is_array($entities)) {
            return null;
        }

        $store = $entities['store'] ?? null;

        if (! is_string($store) || trim($store) === '') {
            return null;
        }

        return $store;
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<string, int|float>
     */
    private function attributes(array $metrics): array
    {
        $attributes = $this->numericAttributes($metrics);

        if ($attributes === []) {
            throw new InvalidArgumentException('New Relic payload metrics must include at least one numeric value.');
        }

        return $attributes;
    }

    /**
     * Collect the numeric (int|float) members of an array as signal attributes,
     * skipping any reserved/non-numeric columns. Shared by the fixture metrics
     * path and the NRQL row path; each caller decides whether an empty result is
     * an error.
     *
     * @param array<string, mixed> $values
     * @param list<string> $excludeKeys
     * @return array<string, int|float>
     */
    private function numericAttributes(array $values, array $excludeKeys = []): array
    {
        $attributes = [];

        foreach ($values as $name => $value) {
            if (! is_string($name) || in_array($name, $excludeKeys, true)) {
                continue;
            }

            if (is_int($value) || is_float($value)) {
                $attributes[$name] = $value;
            }
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function metrics(array $payload): array
    {
        $metrics = $payload['metrics'] ?? null;

        if (! is_array($metrics)) {
            throw new InvalidArgumentException('New Relic payload metrics must be an object.');
        }

        foreach (array_keys($metrics) as $name) {
            if (! is_string($name) || trim($name) === '') {
                throw new InvalidArgumentException('New Relic payload metric names cannot be empty.');
            }
        }

        return $metrics;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stringValue(array $payload, string $name): string
    {
        $value = $payload[$name] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('New Relic payload field "%s" must be a non-empty string.', $name));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function nestedStringValue(array $payload, string $parent, string $name): string
    {
        $parentValue = $payload[$parent] ?? null;

        if (! is_array($parentValue)) {
            throw new InvalidArgumentException(sprintf('New Relic payload field "%s" must be an object.', $parent));
        }

        return $this->stringValue($parentValue, $name);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalNestedStringValue(array $payload, string $parent, string $name): ?string
    {
        $parentValue = $payload[$parent] ?? null;

        if ($parentValue === null) {
            return null;
        }

        if (! is_array($parentValue)) {
            throw new InvalidArgumentException(sprintf('New Relic payload field "%s" must be an object when provided.', $parent));
        }

        return $this->stringValue($parentValue, $name);
    }

    private function timestamp(string $value): DateTimeImmutable
    {
        $timestamp = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);

        if (! $timestamp instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('New Relic payload timestamp must be an ISO-8601 string.');
        }

        return $timestamp;
    }

    private function severity(string $value): SignalSeverity
    {
        return new SignalSeverity(strtolower(trim($value)));
    }
}
