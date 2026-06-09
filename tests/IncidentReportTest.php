<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Mquevedob\Provado\Correlation\CorrelationId;
use Mquevedob\Provado\Incidents\IncidentReport;
use Mquevedob\Provado\Incidents\IncidentReportBuilder;
use Mquevedob\Provado\Incidents\IncidentReportId;
use Mquevedob\Provado\Incidents\IncidentReportRenderer;
use Mquevedob\Provado\Incidents\IncidentReportSeverity;
use Mquevedob\Provado\Patterns\DiagnosticFinding;
use Mquevedob\Provado\Patterns\DiagnosticFindingId;
use Mquevedob\Provado\Patterns\DiagnosticFindingSeverity;
use PHPUnit\Framework\TestCase;
use stdClass;

class IncidentReportTest extends TestCase
{
    public function test_incident_report_id_is_deterministic_regardless_of_finding_order(): void
    {
        $firstFinding = $this->finding('finding-1', 'Checkout degradation', severity: DiagnosticFindingSeverity::warning());
        $secondFinding = $this->finding('finding-2', 'Order sync backlog', severity: DiagnosticFindingSeverity::error());

        $firstId = IncidentReportId::fromFindings([$firstFinding, $secondFinding]);
        $secondId = IncidentReportId::fromFindings([$secondFinding, $firstFinding]);

        $this->assertTrue($firstId->equals($secondId));
    }

    public function test_incident_report_severity_uses_highest_finding_severity(): void
    {
        $severity = IncidentReportSeverity::fromFindings([
            $this->finding('finding-info', 'Informational finding', severity: DiagnosticFindingSeverity::info()),
            $this->finding('finding-critical', 'Critical finding', severity: DiagnosticFindingSeverity::critical()),
            $this->finding('finding-error', 'Error finding', severity: DiagnosticFindingSeverity::error()),
        ]);

        $this->assertTrue($severity->equals(IncidentReportSeverity::critical()));
    }

    public function test_incident_report_validation_rejects_empty_title(): void
    {
        $finding = $this->finding('finding-1', 'Checkout degradation');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IncidentReport title cannot be empty.');

        new IncidentReport(
            id: IncidentReportId::fromFindings([$finding]),
            title: '',
            summary: 'Checkout degraded.',
            severity: IncidentReportSeverity::warning(),
            findings: [$finding],
            evidence: [],
            recommendedNextChecks: [],
            createdAt: new DateTimeImmutable('2026-06-09T10:00:00+00:00'),
        );
    }

    public function test_incident_report_validation_rejects_invalid_findings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IncidentReport findings must be DiagnosticFinding instances.');

        new IncidentReport(
            id: new IncidentReportId('report-1'),
            title: 'Checkout degradation',
            summary: 'Checkout degraded.',
            severity: IncidentReportSeverity::warning(),
            findings: [new stdClass()],
            evidence: [],
            recommendedNextChecks: [],
            createdAt: new DateTimeImmutable('2026-06-09T10:00:00+00:00'),
        );
    }

    public function test_incident_report_builder_returns_null_for_empty_findings(): void
    {
        $this->assertNull((new IncidentReportBuilder())->build([]));
    }

    public function test_incident_report_builder_builds_single_finding_report(): void
    {
        $createdAt = new DateTimeImmutable('2026-06-09T10:00:00+00:00');
        $finding = $this->finding(
            'finding-1',
            'Checkout degradation detected',
            summary: 'Checkout failures correlate with slow transactions.',
            severity: DiagnosticFindingSeverity::error(),
            evidence: ['checkout_failure_rate' => 0.076],
            recommendedNextChecks: ['Inspect checkout application errors.'],
        );

        $report = (new IncidentReportBuilder())->build([$finding], $createdAt);

        $this->assertInstanceOf(IncidentReport::class, $report);
        $this->assertTrue($report->id->equals(IncidentReportId::fromFindings([$finding])));
        $this->assertSame('Checkout degradation detected', $report->title);
        $this->assertSame('Checkout failures correlate with slow transactions.', $report->summary);
        $this->assertTrue($report->severity->equals(IncidentReportSeverity::error()));
        $this->assertSame([$finding], $report->findings);
        $this->assertSame($createdAt, $report->createdAt);
    }

    public function test_incident_report_builder_builds_multi_finding_report(): void
    {
        $checkoutFinding = $this->finding('finding-1', 'Checkout degradation detected');
        $syncFinding = $this->finding('finding-2', 'Order sync backlog detected', severity: DiagnosticFindingSeverity::critical());

        $report = (new IncidentReportBuilder())->build([$checkoutFinding, $syncFinding], new DateTimeImmutable('2026-06-09T10:00:00+00:00'));

        $this->assertSame('Multiple diagnostic findings detected', $report->title);
        $this->assertSame('2 diagnostic findings were detected: Checkout degradation detected; Order sync backlog detected.', $report->summary);
        $this->assertTrue($report->severity->equals(IncidentReportSeverity::critical()));
        $this->assertSame([$checkoutFinding, $syncFinding], $report->findings);
        $this->assertCount(2, $report->evidence);
    }

    public function test_recommended_next_checks_are_deduplicated_preserving_order(): void
    {
        $firstFinding = $this->finding(
            'finding-1',
            'Checkout degradation detected',
            recommendedNextChecks: ['Inspect checkout errors.', 'Check deployments.'],
        );
        $secondFinding = $this->finding(
            'finding-2',
            'Order sync backlog detected',
            recommendedNextChecks: ['Check deployments.', 'Review order sync workers.'],
        );

        $report = (new IncidentReportBuilder())->build([$firstFinding, $secondFinding], new DateTimeImmutable('2026-06-09T10:00:00+00:00'));

        $this->assertSame([
            'Inspect checkout errors.',
            'Check deployments.',
            'Review order sync workers.',
        ], $report->recommendedNextChecks);
    }

    public function test_evidence_is_aggregated_and_sanitized(): void
    {
        $firstFinding = $this->finding(
            'finding-1',
            'Checkout degradation detected',
            evidence: ['metric' => 0.42, 'api_key' => 'secret-value'],
        );
        $secondFinding = $this->finding(
            'finding-2',
            'Order sync backlog detected',
            evidence: ['nested' => ['access_token' => 'nested-secret', 'safe' => 'kept']],
        );

        $report = (new IncidentReportBuilder())->build([$firstFinding, $secondFinding], new DateTimeImmutable('2026-06-09T10:00:00+00:00'));
        $encodedEvidence = json_encode($report->evidence, JSON_THROW_ON_ERROR);

        $this->assertSame('finding-1', $report->evidence[0]['finding_id']);
        $this->assertSame(0.42, $report->evidence[0]['evidence']['metric']);
        $this->assertSame('[redacted]', $report->evidence[0]['evidence']['api_key']);
        $this->assertSame('[redacted]', $report->evidence[1]['evidence']['nested']['access_token']);
        $this->assertSame('kept', $report->evidence[1]['evidence']['nested']['safe']);
        $this->assertStringNotContainsString('secret-value', $encodedEvidence);
        $this->assertStringNotContainsString('nested-secret', $encodedEvidence);
    }

    public function test_incident_report_renderer_outputs_deterministic_readable_text(): void
    {
        $finding = $this->finding(
            'finding-1',
            'Checkout degradation detected',
            summary: 'Checkout failures correlate with slow transactions.',
            severity: DiagnosticFindingSeverity::error(),
            evidence: ['checkout_failure_rate' => 0.076],
            recommendedNextChecks: ['Inspect checkout application errors.'],
        );
        $createdAt = new DateTimeImmutable('2026-06-09T10:00:00+00:00');
        $report = (new IncidentReportBuilder())->build([$finding], $createdAt);

        $expected = 'Incident Report' . "\n"
            . '===============' . "\n"
            . 'ID: '.$report->id->value . "\n"
            . 'Created At: 2026-06-09T10:00:00+00:00' . "\n"
            . 'Title: Checkout degradation detected' . "\n"
            . 'Severity: error' . "\n"
            . 'Summary: Checkout failures correlate with slow transactions.' . "\n"
            . 'Finding Count: 1' . "\n"
            . "\n"
            . 'Evidence:' . "\n"
            . '[' . "\n"
            . '    {' . "\n"
            . '        "finding_id": "finding-1",' . "\n"
            . '        "title": "Checkout degradation detected",' . "\n"
            . '        "evidence": {' . "\n"
            . '            "checkout_failure_rate": 0.076' . "\n"
            . '        }' . "\n"
            . '    }' . "\n"
            . ']' . "\n"
            . "\n"
            . 'Recommended Next Checks:' . "\n"
            . '- Inspect checkout application errors.' . "\n";

        $this->assertSame($expected, (new IncidentReportRenderer())->renderText($report));
    }

    public function test_incident_report_renderer_does_not_expose_secret_evidence(): void
    {
        $finding = $this->finding(
            'finding-1',
            'Checkout degradation detected',
            evidence: ['authorization' => 'Bearer secret-token', 'safe' => 'visible'],
        );
        $report = (new IncidentReportBuilder())->build([$finding], new DateTimeImmutable('2026-06-09T10:00:00+00:00'));

        $rendered = (new IncidentReportRenderer())->renderText($report);

        $this->assertStringContainsString('[redacted]', $rendered);
        $this->assertStringContainsString('visible', $rendered);
        $this->assertStringNotContainsString('Bearer secret-token', $rendered);
    }

    /**
     * @param array<mixed> $evidence
     * @param array<mixed> $recommendedNextChecks
     */
    private function finding(
        string $id,
        string $title,
        string $summary = 'Diagnostic finding summary.',
        ?DiagnosticFindingSeverity $severity = null,
        array $evidence = ['service' => 'checkout'],
        array $recommendedNextChecks = ['Inspect checkout application errors.'],
    ): DiagnosticFinding {
        return new DiagnosticFinding(
            id: new DiagnosticFindingId($id),
            patternId: 'pattern-'.$id,
            title: $title,
            summary: $summary,
            severity: $severity ?? DiagnosticFindingSeverity::warning(),
            correlationId: new CorrelationId('correlation-'.$id),
            evidence: $evidence,
            recommendedNextChecks: $recommendedNextChecks,
        );
    }
}
