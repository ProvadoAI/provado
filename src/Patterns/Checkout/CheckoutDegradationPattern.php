<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Patterns\Checkout;

use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Correlation\CorrelationGroup;
use Mquevedob\Provado\Patterns\DiagnosticEvidence;
use Mquevedob\Provado\Patterns\DiagnosticFinding;
use Mquevedob\Provado\Patterns\DiagnosticFindingId;
use Mquevedob\Provado\Patterns\DiagnosticPattern;
use Mquevedob\Provado\Patterns\PatternEvaluationResult;

final readonly class CheckoutDegradationPattern implements DiagnosticPattern
{
    use DiagnosticEvidence;

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

    /**
     * @return array<string, mixed>
     */
    private function evidence(CorrelationGroup $group): array
    {
        $evidence = $this->withMetricEvidence(
            $this->baseEvidence($group),
            $group,
            ['duration_ms', 'error_rate', 'throughput'],
        );

        $checkoutFailureRate = $this->firstMetricValue($group, 'failure_rate')
            ?? $this->firstMetricValue($group, 'checkout_failure_rate');

        if ($checkoutFailureRate !== null) {
            $evidence['checkout_failure_rate'] = $checkoutFailureRate;
        }

        return $evidence;
    }
}
