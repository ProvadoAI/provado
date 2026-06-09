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
     * @param array<string, mixed> $payload
     */
    public function map(array $payload): Signal
    {
        $fixtureId = $this->stringValue($payload, 'id');
        $eventType = $this->stringValue($payload, 'event_type');
        $severity = $this->severity($this->stringValue($payload, 'severity'));
        $timestamp = $this->timestamp($this->stringValue($payload, 'timestamp'));
        $applicationName = $this->nestedStringValue($payload, 'application', 'name');
        $transactionName = $this->optionalNestedStringValue($payload, 'transaction', 'name');
        $metrics = $this->metrics($payload);

        return new Signal(
            id: new SignalId('new_relic:'.$fixtureId),
            source: new SignalSource('new_relic'),
            type: new SignalType($eventType),
            timestamp: $timestamp,
            severity: $severity,
            entityReferences: $this->entityReferences($applicationName, $transactionName),
            attributes: $this->attributes($metrics),
            rawPayloadReference: new RawPayloadReference($fixtureId, 'tests/Fixtures/new_relic/'.$fixtureId.'.json'),
        );
    }

    /**
     * @return list<EntityReference>
     */
    private function entityReferences(string $applicationName, ?string $transactionName): array
    {
        $references = [new EntityReference('service', $applicationName)];

        if ($transactionName !== null) {
            $references[] = new EntityReference('transaction', $transactionName);
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

        foreach ($metrics as $name => $value) {
            if (is_int($value) || is_float($value)) {
                $attributes[$name] = $value;
            }
        }

        if ($attributes === []) {
            throw new InvalidArgumentException('New Relic payload metrics must include at least one numeric value.');
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
