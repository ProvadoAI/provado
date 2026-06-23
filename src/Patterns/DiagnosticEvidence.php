<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Patterns;

use Mquevedob\Provado\Core\EntityReference;
use Mquevedob\Provado\Core\SignalSeverity;
use Mquevedob\Provado\Correlation\CorrelationGroup;

/**
 * Shared evidence/severity helpers for correlation-backed diagnostic patterns.
 *
 * Every pattern surfaces the same base evidence (involved sources/types, shared
 * entities, the group's time span and highest severity) and then layers its own
 * numeric metrics on top. Keeping that in one place avoids re-implementing it
 * per pattern.
 */
trait DiagnosticEvidence
{
    private function findingSeverity(SignalSeverity $highestSignalSeverity): DiagnosticFindingSeverity
    {
        return match ($highestSignalSeverity->value) {
            'critical' => DiagnosticFindingSeverity::critical(),
            'error' => DiagnosticFindingSeverity::error(),
            default => DiagnosticFindingSeverity::warning(),
        };
    }

    /**
     * The evidence fields shared by every correlation-backed pattern. Concrete
     * patterns merge their own metric values on top via withMetricEvidence().
     *
     * @return array<string, mixed>
     */
    private function baseEvidence(CorrelationGroup $group): array
    {
        return [
            'correlation_id' => $group->id->value,
            'involved_sources' => $this->sourceValues($group),
            'involved_types' => $this->typeValues($group),
            'shared_entities' => $this->entityValues($group->sharedEntities()),
            'starts_at' => $group->startsAt()->format(DATE_ATOM),
            'ends_at' => $group->endsAt()->format(DATE_ATOM),
            'highest_signal_severity' => $group->highestSeverity()->value,
        ];
    }

    /**
     * Append the named numeric metrics to the evidence, skipping any metric
     * absent from the group.
     *
     * @param array<string, mixed> $evidence
     * @param list<string> $metricNames
     * @return array<string, mixed>
     */
    private function withMetricEvidence(array $evidence, CorrelationGroup $group, array $metricNames): array
    {
        foreach ($metricNames as $metricName) {
            $metricValue = $this->firstMetricValue($group, $metricName);

            if ($metricValue !== null) {
                $evidence[$metricName] = $metricValue;
            }
        }

        return $evidence;
    }

    /**
     * @return list<string>
     */
    private function sourceValues(CorrelationGroup $group): array
    {
        $sources = [];

        foreach ($group->involvedSources() as $source) {
            $sources[] = $source->value;
        }

        return $sources;
    }

    /**
     * @return list<string>
     */
    private function typeValues(CorrelationGroup $group): array
    {
        $types = [];

        foreach ($group->involvedTypes() as $type) {
            $types[] = $type->value;
        }

        return $types;
    }

    /**
     * @param list<EntityReference> $entityReferences
     * @return list<array{type: string, id: string}>
     */
    private function entityValues(array $entityReferences): array
    {
        $entities = [];

        foreach ($entityReferences as $entityReference) {
            $entities[] = [
                'type' => $entityReference->type,
                'id' => $entityReference->id,
            ];
        }

        return $entities;
    }

    private function firstMetricValue(CorrelationGroup $group, string $metricName): int|float|null
    {
        foreach ($group->signals as $signal) {
            $metricValue = $signal->attributes[$metricName] ?? null;

            if (is_int($metricValue) || is_float($metricValue)) {
                return $metricValue;
            }
        }

        return null;
    }
}
