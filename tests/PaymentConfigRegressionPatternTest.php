<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests;

use DateTimeImmutable;
use Mquevedob\Provado\Config\SourceConfig;
use Mquevedob\Provado\Config\SourceCredentials;
use Mquevedob\Provado\Core\EntityReference;
use Mquevedob\Provado\Core\RawPayloadReference;
use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Core\SignalId;
use Mquevedob\Provado\Core\SignalSeverity;
use Mquevedob\Provado\Core\SignalSource;
use Mquevedob\Provado\Core\SignalType;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Correlation\CorrelationGroup;
use Mquevedob\Provado\Patterns\DiagnosticFindingId;
use Mquevedob\Provado\Patterns\DiagnosticFindingSeverity;
use Mquevedob\Provado\Patterns\Payments\PaymentConfigRegressionPattern;
use Mquevedob\Provado\Sources\AdobeCommerce\AdobeCommerceAdapter;
use PHPUnit\Framework\TestCase;

class PaymentConfigRegressionPatternTest extends TestCase
{
    public function test_id_and_name(): void
    {
        $pattern = new PaymentConfigRegressionPattern();

        $this->assertSame('payment_config_regression', $pattern->id());
        $this->assertSame('Payment configuration regression', $pattern->name());
    }

    public function test_supports_false_when_only_checkout_failure_rate_exists(): void
    {
        $group = new CorrelationGroup([$this->checkoutFailureSignal('a')]);

        $this->assertFalse((new PaymentConfigRegressionPattern())->supports($group));
    }

    public function test_supports_false_when_only_payment_config_change_exists(): void
    {
        $group = new CorrelationGroup([$this->paymentConfigChangeSignal('a')]);

        $this->assertFalse((new PaymentConfigRegressionPattern())->supports($group));
    }

    public function test_supports_true_for_payment_config_change(): void
    {
        $group = new CorrelationGroup([
            $this->checkoutFailureSignal('a'),
            $this->paymentConfigChangeSignal('b'),
        ]);

        $this->assertTrue((new PaymentConfigRegressionPattern())->supports($group));
    }

    public function test_supports_true_for_three_ds_config_change(): void
    {
        $group = new CorrelationGroup([
            $this->checkoutFailureSignal('a'),
            $this->paymentConfigChangeSignal('b', type: 'three_ds_config_change'),
        ]);

        $this->assertTrue((new PaymentConfigRegressionPattern())->supports($group));
    }

    public function test_evaluate_unsupported_group_returns_none(): void
    {
        $result = (new PaymentConfigRegressionPattern())->evaluate(
            new CorrelationGroup([$this->checkoutFailureSignal('a')]),
        );

        $this->assertFalse($result->hasFindings());
    }

    public function test_evaluate_supported_group_returns_one_finding(): void
    {
        $group = $this->supportedGroup();
        $result = (new PaymentConfigRegressionPattern())->evaluate($group);

        $this->assertCount(1, $result->findings());
        $this->assertSame('Payment configuration regression detected', $result->findings()[0]->title);
        $this->assertSame('payment_config_regression', $result->findings()[0]->patternId);
        $this->assertTrue($result->findings()[0]->correlationId->equals($group->id));
    }

    public function test_finding_id_is_deterministic(): void
    {
        $pattern = new PaymentConfigRegressionPattern();
        $group = $this->supportedGroup();
        $finding = $pattern->evaluate($group)->findings()[0];

        $this->assertTrue($finding->id->equals(DiagnosticFindingId::fromPatternAndCorrelation($pattern->id(), $group->id)));
    }

    public function test_finding_severity_follows_highest_group_severity(): void
    {
        $pattern = new PaymentConfigRegressionPattern();

        $finding = $pattern->evaluate(new CorrelationGroup([
            $this->checkoutFailureSignal('a', severity: SignalSeverity::error()),
            $this->paymentConfigChangeSignal('b', severity: SignalSeverity::warning()),
        ]))->findings()[0];

        $this->assertTrue($finding->severity->equals(DiagnosticFindingSeverity::error()));
    }

    public function test_finding_evidence_contains_base_fields_and_metrics(): void
    {
        $finding = (new PaymentConfigRegressionPattern())->evaluate($this->supportedGroup())->findings()[0];

        $this->assertSame(['adobe_commerce'], $finding->evidence['involved_sources']);
        $this->assertSame(['checkout_failure_rate', 'payment_config_change'], $finding->evidence['involved_types']);
        $this->assertSame(0.41, $finding->evidence['payment_decline_rate']);
        $this->assertSame(0.076, $finding->evidence['failure_rate']);
    }

    public function test_recommended_next_checks_are_present(): void
    {
        $finding = (new PaymentConfigRegressionPattern())->evaluate($this->supportedGroup())->findings()[0];

        $this->assertNotEmpty($finding->recommendedNextChecks);
        $this->assertContainsOnly('string', $finding->recommendedNextChecks);
    }

    public function test_pattern_is_diagnosable_from_fixtures(): void
    {
        $result = (new AdobeCommerceAdapter())->fetch(
            new SourceConfig(
                name: 'adobe_commerce',
                enabled: true,
                options: ['fixtures' => ['checkout_failure_rate', 'payment_config_change']],
                credentials: new SourceCredentials(),
            ),
            new TimeWindow(
                start: new DateTimeImmutable('2026-06-08T12:00:00+00:00'),
                end: new DateTimeImmutable('2026-06-08T12:30:00+00:00'),
            ),
        );

        $this->assertCount(2, $result->signals());

        $group = new CorrelationGroup($result->signals());
        $finding = (new PaymentConfigRegressionPattern())->evaluate($group)->findings()[0];

        $this->assertSame('payment_config_regression', $finding->patternId);
        $this->assertSame(0.41, $finding->evidence['payment_decline_rate']);
    }

    private function supportedGroup(): CorrelationGroup
    {
        return new CorrelationGroup([
            $this->checkoutFailureSignal('a'),
            $this->paymentConfigChangeSignal('b'),
        ]);
    }

    private function checkoutFailureSignal(string $id, ?SignalSeverity $severity = null): Signal
    {
        return new Signal(
            id: new SignalId('adobe_commerce:'.$id),
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

    private function paymentConfigChangeSignal(
        string $id,
        string $type = 'payment_config_change',
        ?SignalSeverity $severity = null,
    ): Signal {
        return new Signal(
            id: new SignalId('adobe_commerce:'.$id),
            source: new SignalSource('adobe_commerce'),
            type: new SignalType($type),
            timestamp: new DateTimeImmutable('2026-06-08T12:01:00+00:00'),
            severity: $severity ?? SignalSeverity::warning(),
            entityReferences: [
                new EntityReference('store', 'default'),
                new EntityReference('checkout', 'onepage'),
                new EntityReference('payment', 'braintree'),
            ],
            attributes: ['payment_decline_rate' => 0.41],
            rawPayloadReference: new RawPayloadReference($id),
        );
    }
}
