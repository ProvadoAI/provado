<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Patterns\Payments;

use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Correlation\CorrelationGroup;
use Mquevedob\Provado\Patterns\DiagnosticEvidence;
use Mquevedob\Provado\Patterns\DiagnosticFinding;
use Mquevedob\Provado\Patterns\DiagnosticFindingId;
use Mquevedob\Provado\Patterns\DiagnosticPattern;
use Mquevedob\Provado\Patterns\PatternEvaluationResult;

/**
 * Detects a payment / 3DS configuration regression: a checkout failure-rate
 * spike correlated with a recent payment or 3-D Secure configuration change.
 * This is the v1 lead pattern — a config change that quietly breaks payment
 * authorization is a common, high-impact, fully fixture-diagnosable failure.
 */
final readonly class PaymentConfigRegressionPattern implements DiagnosticPattern
{
    use DiagnosticEvidence;

    private const ADOBE_COMMERCE_SOURCE = 'adobe_commerce';

    private const CHECKOUT_FAILURE_RATE_TYPE = 'checkout_failure_rate';

    /**
     * @var list<string>
     */
    private const PAYMENT_CONFIG_CHANGE_TYPES = [
        'payment_config_change',
        'three_ds_config_change',
    ];

    /**
     * @var list<string>
     */
    private const METRICS = [
        'payment_decline_rate',
        'failure_rate',
    ];

    /**
     * @var list<string>
     */
    private const RECOMMENDED_NEXT_CHECKS = [
        'Review the most recent payment/3DS configuration change and consider rolling it back.',
        'Check payment gateway responses and 3-D Secure authentication outcomes for declines.',
        'Compare checkout failure rate before and after the configuration change.',
        'Validate payment method availability and credentials in the affected store.',
    ];

    public function id(): string
    {
        return 'payment_config_regression';
    }

    public function name(): string
    {
        return 'Payment configuration regression';
    }

    public function supports(CorrelationGroup $group): bool
    {
        $hasCheckoutFailureRateSignal = false;
        $hasPaymentConfigChangeSignal = false;

        foreach ($group->signals as $signal) {
            if ($this->isCheckoutFailureRateSignal($signal)) {
                $hasCheckoutFailureRateSignal = true;
            }

            if ($this->isPaymentConfigChangeSignal($signal)) {
                $hasPaymentConfigChangeSignal = true;
            }
        }

        return $hasCheckoutFailureRateSignal && $hasPaymentConfigChangeSignal;
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
                title: 'Payment configuration regression detected',
                summary: 'A checkout failure-rate spike was correlated with a recent payment/3DS configuration change, indicating the change likely regressed payment authorization.',
                severity: $this->findingSeverity($group->highestSeverity()),
                correlationId: $group->id,
                evidence: $this->withMetricEvidence($this->baseEvidence($group), $group, self::METRICS),
                recommendedNextChecks: self::RECOMMENDED_NEXT_CHECKS,
            ),
        ]);
    }

    private function isCheckoutFailureRateSignal(Signal $signal): bool
    {
        return $signal->source->value === self::ADOBE_COMMERCE_SOURCE
            && $signal->type->value === self::CHECKOUT_FAILURE_RATE_TYPE;
    }

    private function isPaymentConfigChangeSignal(Signal $signal): bool
    {
        return $signal->source->value === self::ADOBE_COMMERCE_SOURCE
            && in_array($signal->type->value, self::PAYMENT_CONFIG_CHANGE_TYPES, true);
    }
}
