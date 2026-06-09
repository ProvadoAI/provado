<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Sources\AdobeCommerce;

use DateTimeImmutable;
use InvalidArgumentException;
use Mquevedob\Provado\Core\EntityReference;
use Mquevedob\Provado\Core\RawPayloadReference;
use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Core\SignalId;
use Mquevedob\Provado\Core\SignalSeverity;
use Mquevedob\Provado\Core\SignalSource;
use Mquevedob\Provado\Core\SignalType;

final readonly class AdobeCommercePayloadMapper
{
    /**
     * @param array<string, mixed> $payload
     */
    public function map(array $payload): Signal
    {
        $fixtureId = $this->stringValue($payload, 'id');
        $eventType = $this->stringValue($payload, 'event_type');
        $severity = $this->severity($this->stringValue($payload, 'severity'));
        $timestamp = $this->timestamp($this->stringValue($payload, 'timestamp'));
        $entities = $this->entities($payload);
        $metrics = $this->metrics($payload);

        return new Signal(
            id: new SignalId('adobe_commerce:'.$fixtureId),
            source: new SignalSource('adobe_commerce'),
            type: new SignalType($eventType),
            timestamp: $timestamp,
            severity: $severity,
            entityReferences: $this->entityReferences($entities),
            attributes: $this->attributes($metrics),
            rawPayloadReference: new RawPayloadReference($fixtureId, 'tests/Fixtures/adobe_commerce/'.$fixtureId.'.json'),
        );
    }

    /**
     * @param array<string, mixed> $entities
     * @return list<EntityReference>
     */
    private function entityReferences(array $entities): array
    {
        $references = [];

        foreach (['store', 'checkout', 'queue', 'sku', 'indexer'] as $entityType) {
            $entityId = $entities[$entityType] ?? null;

            if ($entityId === null) {
                continue;
            }

            if (! is_string($entityId) || trim($entityId) === '') {
                throw new InvalidArgumentException(sprintf('Adobe Commerce payload entity "%s" must be a non-empty string when provided.', $entityType));
            }

            $references[] = new EntityReference($entityType, $entityId);
        }

        if ($references === []) {
            throw new InvalidArgumentException('Adobe Commerce payload entities must include at least one supported entity.');
        }

        return $references;
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<string, int|float>
     */
    private function attributes(array $metrics): array
    {
        $attributes = [];

        foreach (['failure_rate', 'backlog_count', 'drift_count', 'stuck_duration_minutes'] as $metricName) {
            $metricValue = $metrics[$metricName] ?? null;

            if ($metricValue === null) {
                continue;
            }

            if (! is_int($metricValue) && ! is_float($metricValue)) {
                throw new InvalidArgumentException(sprintf('Adobe Commerce payload metric "%s" must be numeric when provided.', $metricName));
            }

            $attributes[$metricName] = $metricValue;
        }

        if ($attributes === []) {
            throw new InvalidArgumentException('Adobe Commerce payload metrics must include at least one supported numeric value.');
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function entities(array $payload): array
    {
        $entities = $payload['entities'] ?? null;

        if (! is_array($entities)) {
            throw new InvalidArgumentException('Adobe Commerce payload entities must be an object.');
        }

        foreach (array_keys($entities) as $name) {
            if (! is_string($name) || trim($name) === '') {
                throw new InvalidArgumentException('Adobe Commerce payload entity names cannot be empty.');
            }
        }

        return $entities;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function metrics(array $payload): array
    {
        $metrics = $payload['metrics'] ?? null;

        if (! is_array($metrics)) {
            throw new InvalidArgumentException('Adobe Commerce payload metrics must be an object.');
        }

        foreach (array_keys($metrics) as $name) {
            if (! is_string($name) || trim($name) === '') {
                throw new InvalidArgumentException('Adobe Commerce payload metric names cannot be empty.');
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
            throw new InvalidArgumentException(sprintf('Adobe Commerce payload field "%s" must be a non-empty string.', $name));
        }

        return $value;
    }

    private function timestamp(string $value): DateTimeImmutable
    {
        $timestamp = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);

        if (! $timestamp instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('Adobe Commerce payload timestamp must be an ISO-8601 string.');
        }

        return $timestamp;
    }

    private function severity(string $value): SignalSeverity
    {
        return new SignalSeverity(strtolower(trim($value)));
    }
}
