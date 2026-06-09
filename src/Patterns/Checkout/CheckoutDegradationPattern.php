<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Patterns\Checkout;

use Mquevedob\Provado\Core\EntityReference;
use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Core\SignalSeverity;
use Mquevedob\Provado\Correlation\CorrelationGroup;
use Mquevedob\Provado\Patterns\DiagnosticFinding;
use Mquevedob\Provado\Patterns\DiagnosticFindingId;
use Mquevedob\Provado\Patterns\DiagnosticFindingSeverity;
use Mquevedob\Provado\Patterns\DiagnosticPattern;
use Mquevedob\Provado\Patterns\PatternEvaluationResult;

final readonly class CheckoutDegradationPattern implements DiagnosticPattern
{
    private const ADOBE_COMMERCE_SOURCE = 'adobe_commerce';

    private const CHECKOUT_FAILURE_RATE_TYPE = 'checkout_failure_rate';

    private const NEW_RELIC_SOURCE = 'new_relic';

    private const NEW_RELIC_DEGRADATION_TYPES = [
        'transaction_slowdown',
        'latency_spike',
        'error_rate_spike',
    ];

    /**
     * @var list<string>
     */
    private const RECOMMENDED_NEXT_CHECKS = [
        'Inspect checkout application errors and slow transactions.',
        'Check recent deployments affecting checkout.',
        'Review Adobe Commerce checkout logs and payment gateway responses.',
        'Validate cache/session behavior around checkout.',
    ];

    public function id(): string
    {
        return 'checkout_degradation';
    }

    public function name(): string
    {
        return 'Checkout degradation';
    }

    public function supports(CorrelationGroup $group): bool
    {
        $hasCheckoutFailureRateSignal = false;
        $hasObservabilityDegradationSignal = false;

        foreach ($group->signals as $signal) {
            if ($this->isCheckoutFailureRateSignal($signal)) {
                $hasCheckoutFailureRateSignal = true;
            }

            if ($this->isObservabilityDegradationSignal($signal)) {
                $hasObservabilityDegradationSignal = true;
            }
        }

        return $hasCheckoutFailureRateSignal && $hasObservabilityDegradationSignal;
    }

    public function evaluate(CorrelationGroup $group): PatternEvaluationResult
    {
        if (! $this->supports($group)) {
            return PatternEvaluationResult::none();
        }

        return PatternEvaluationResult::fromFindings([
            new DiagnosticFinding(
                id: DiagnosticFindingId::fromPatternAndCorrelation($this->id(), $group->id),
                patternId: $this->id(),
                title: 'Checkout degradation detected',
                summary: 'Checkout failure signals and observability degradation were correlated in the same signal group.',
                severity: $this->findingSeverity($group->highestSeverity()),
                correlationId: $group->id,
                evidence: $this->evidence($group),
                recommendedNextChecks: self::RECOMMENDED_NEXT_CHECKS,
            ),
        ]);
    }

    private function isCheckoutFailureRateSignal(Signal $signal): bool
    {
        return $signal->source->value === self::ADOBE_COMMERCE_SOURCE
            && $signal->type->value === self::CHECKOUT_FAILURE_RATE_TYPE;
    }

    private function isObservabilityDegradationSignal(Signal $signal): bool
    {
        return $signal->source->value === self::NEW_RELIC_SOURCE
            && in_array($signal->type->value, self::NEW_RELIC_DEGRADATION_TYPES, true);
    }

    private function findingSeverity(SignalSeverity $highestSignalSeverity): DiagnosticFindingSeverity
    {
        return match ($highestSignalSeverity->value) {
            'critical' => DiagnosticFindingSeverity::critical(),
            'error' => DiagnosticFindingSeverity::error(),
            default => DiagnosticFindingSeverity::warning(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function evidence(CorrelationGroup $group): array
    {
        $evidence = [
            'correlation_id' => $group->id->value,
            'involved_sources' => $this->sourceValues($group),
            'involved_types' => $this->typeValues($group),
            'shared_entities' => $this->entityValues($group->sharedEntities()),
            'starts_at' => $group->startsAt()->format(DATE_ATOM),
            'ends_at' => $group->endsAt()->format(DATE_ATOM),
            'highest_signal_severity' => $group->highestSeverity()->value,
        ];

        foreach (['duration_ms', 'error_rate', 'throughput'] as $metricName) {
            $metricValue = $this->firstMetricValue($group, $metricName);

            if ($metricValue !== null) {
                $evidence[$metricName] = $metricValue;
            }
        }

        $checkoutFailureRate = $this->firstMetricValue($group, 'failure_rate')
            ?? $this->firstMetricValue($group, 'checkout_failure_rate');

        if ($checkoutFailureRate !== null) {
            $evidence['checkout_failure_rate'] = $checkoutFailureRate;
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
