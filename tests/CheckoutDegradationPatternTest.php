<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests;

use DateTimeImmutable;
use Mquevedob\Provado\Core\EntityReference;
use Mquevedob\Provado\Core\RawPayloadReference;
use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Core\SignalId;
use Mquevedob\Provado\Core\SignalSeverity;
use Mquevedob\Provado\Core\SignalSource;
use Mquevedob\Provado\Core\SignalType;
use Mquevedob\Provado\Correlation\CorrelationGroup;
use Mquevedob\Provado\Patterns\Checkout\CheckoutDegradationPattern;
use Mquevedob\Provado\Patterns\DiagnosticFindingId;
use Mquevedob\Provado\Patterns\DiagnosticFindingSeverity;
use PHPUnit\Framework\TestCase;

class CheckoutDegradationPatternTest extends TestCase
{
    public function test_id_and_name(): void
    {
        $pattern = new CheckoutDegradationPattern();

        $this->assertSame('checkout_degradation', $pattern->id());
        $this->assertSame('Checkout degradation', $pattern->name());
    }

    public function test_supports_false_when_only_new_relic_signals_exist(): void
    {
        $pattern = new CheckoutDegradationPattern();
        $group = new CorrelationGroup([
            $this->newRelicSignal('new-relic-1', 'transaction_slowdown'),
            $this->newRelicSignal('new-relic-2', 'latency_spike'),
        ]);

        $this->assertFalse($pattern->supports($group));
    }

    public function test_supports_false_when_only_adobe_commerce_checkout_signal_exists(): void
    {
        $pattern = new CheckoutDegradationPattern();
        $group = new CorrelationGroup([
            $this->adobeCommerceCheckoutFailureSignal('adobe-commerce-1'),
        ]);

        $this->assertFalse($pattern->supports($group));
    }

    public function test_supports_true_when_checkout_failure_rate_and_transaction_slowdown_are_correlated(): void
    {
        $this->assertSupportsNewRelicType('transaction_slowdown');
    }

    public function test_supports_true_with_latency_spike(): void
    {
        $this->assertSupportsNewRelicType('latency_spike');
    }

    public function test_supports_true_with_error_rate_spike(): void
    {
        $this->assertSupportsNewRelicType('error_rate_spike');
    }

    public function test_evaluate_unsupported_group_returns_none(): void
    {
        $result = (new CheckoutDegradationPattern())->evaluate(new CorrelationGroup([
            $this->newRelicSignal('new-relic-1', 'transaction_slowdown'),
        ]));

        $this->assertFalse($result->hasFindings());
        $this->assertSame([], $result->findings());
    }

    public function test_evaluate_supported_group_returns_one_finding(): void
    {
        $group = $this->supportedGroup();
        $result = (new CheckoutDegradationPattern())->evaluate($group);

        $this->assertTrue($result->hasFindings());
        $this->assertCount(1, $result->findings());
        $this->assertSame('Checkout degradation detected', $result->findings()[0]->title);
        $this->assertSame('checkout_degradation', $result->findings()[0]->patternId);
        $this->assertTrue($result->findings()[0]->correlationId->equals($group->id));
    }

    public function test_finding_id_is_deterministic(): void
    {
        $pattern = new CheckoutDegradationPattern();
        $group = $this->supportedGroup();
        $finding = $pattern->evaluate($group)->findings()[0];

        $this->assertTrue($finding->id->equals(DiagnosticFindingId::fromPatternAndCorrelation($pattern->id(), $group->id)));
        $this->assertTrue($finding->id->equals($pattern->evaluate($group)->findings()[0]->id));
    }

    public function test_finding_severity_follows_highest_group_severity(): void
    {
        $pattern = new CheckoutDegradationPattern();

        $criticalFinding = $pattern->evaluate(new CorrelationGroup([
            $this->adobeCommerceCheckoutFailureSignal('adobe-commerce-critical', severity: SignalSeverity::error()),
            $this->newRelicSignal('new-relic-critical', 'transaction_slowdown', severity: SignalSeverity::critical()),
        ]))->findings()[0];
        $errorFinding = $pattern->evaluate(new CorrelationGroup([
            $this->adobeCommerceCheckoutFailureSignal('adobe-commerce-error', severity: SignalSeverity::error()),
            $this->newRelicSignal('new-relic-error', 'latency_spike', severity: SignalSeverity::warning()),
        ]))->findings()[0];
        $warningFinding = $pattern->evaluate(new CorrelationGroup([
            $this->adobeCommerceCheckoutFailureSignal('adobe-commerce-warning', severity: SignalSeverity::warning()),
            $this->newRelicSignal('new-relic-warning', 'latency_spike', severity: SignalSeverity::warning()),
        ]))->findings()[0];

        $this->assertTrue($criticalFinding->severity->equals(DiagnosticFindingSeverity::critical()));
        $this->assertTrue($errorFinding->severity->equals(DiagnosticFindingSeverity::error()));
        $this->assertTrue($warningFinding->severity->equals(DiagnosticFindingSeverity::warning()));
    }

    public function test_finding_evidence_contains_sources_types_shared_entities_timestamps_and_metrics(): void
    {
        $group = $this->supportedGroup();
        $finding = (new CheckoutDegradationPattern())->evaluate($group)->findings()[0];

        $this->assertSame($group->id->value, $finding->evidence['correlation_id']);
        $this->assertSame(['adobe_commerce', 'new_relic'], $finding->evidence['involved_sources']);
        $this->assertSame(['checkout_failure_rate', 'transaction_slowdown'], $finding->evidence['involved_types']);
        $this->assertSame([['type' => 'store', 'id' => 'default']], $finding->evidence['shared_entities']);
        $this->assertSame('2026-06-08T12:00:00+00:00', $finding->evidence['starts_at']);
        $this->assertSame('2026-06-08T12:10:00+00:00', $finding->evidence['ends_at']);
        $this->assertSame('critical', $finding->evidence['highest_signal_severity']);
        $this->assertSame(0.076, $finding->evidence['checkout_failure_rate']);
        $this->assertSame(5200, $finding->evidence['duration_ms']);
        $this->assertSame(0.084, $finding->evidence['error_rate']);
        $this->assertSame(43, $finding->evidence['throughput']);
    }

    public function test_finding_recommended_next_checks_are_present(): void
    {
        $finding = (new CheckoutDegradationPattern())->evaluate($this->supportedGroup())->findings()[0];

        $this->assertSame([
            'Inspect checkout application errors and slow transactions.',
            'Check recent deployments affecting checkout.',
            'Review Adobe Commerce checkout logs and payment gateway responses.',
            'Validate cache/session behavior around checkout.',
        ], $finding->recommendedNextChecks);
    }

    private function assertSupportsNewRelicType(string $newRelicType): void
    {
        $pattern = new CheckoutDegradationPattern();
        $group = new CorrelationGroup([
            $this->adobeCommerceCheckoutFailureSignal('adobe-commerce-1'),
            $this->newRelicSignal('new-relic-1', $newRelicType),
        ]);

        $this->assertTrue($pattern->supports($group));
    }

    private function supportedGroup(): CorrelationGroup
    {
        return new CorrelationGroup([
            $this->adobeCommerceCheckoutFailureSignal('adobe-commerce-1'),
            $this->newRelicSignal('new-relic-1', 'transaction_slowdown', attributes: [
                'duration_ms' => 5200,
                'error_rate' => 0.084,
                'throughput' => 43,
            ]),
        ]);
    }

    /**
     * @param array<string, int|float> $attributes
     */
    private function newRelicSignal(
        string $id,
        string $type,
        ?SignalSeverity $severity = null,
        array $attributes = ['duration_ms' => 5200, 'throughput' => 43],
    ): Signal {
        return new Signal(
            id: new SignalId($id),
            source: new SignalSource('new_relic'),
            type: new SignalType($type),
            timestamp: new DateTimeImmutable('2026-06-08T12:10:00+00:00'),
            severity: $severity ?? SignalSeverity::critical(),
            entityReferences: [
                new EntityReference('store', 'default'),
                new EntityReference('service', 'checkout-api'),
            ],
            attributes: $attributes,
            rawPayloadReference: new RawPayloadReference($id),
        );
    }

    private function adobeCommerceCheckoutFailureSignal(
        string $id,
        ?SignalSeverity $severity = null,
    ): Signal {
        return new Signal(
            id: new SignalId($id),
            source: new SignalSource('adobe_commerce'),
            type: new SignalType('checkout_failure_rate'),
            timestamp: new DateTimeImmutable('2026-06-08T12:00:00+00:00'),
            severity: $severity ?? SignalSeverity::error(),
            entityReferences: [
                new EntityReference('store', 'default'),
                new EntityReference('checkout', 'onepage'),
            ],
            attributes: ['failure_rate' => 0.076],
            rawPayloadReference: new RawPayloadReference($id),
        );
    }
}
