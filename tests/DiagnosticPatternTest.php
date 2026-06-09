<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Mquevedob\Provado\Core\EntityReference;
use Mquevedob\Provado\Core\RawPayloadReference;
use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Core\SignalId;
use Mquevedob\Provado\Core\SignalSeverity;
use Mquevedob\Provado\Core\SignalSource;
use Mquevedob\Provado\Core\SignalType;
use Mquevedob\Provado\Correlation\CorrelationGroup;
use Mquevedob\Provado\Patterns\DiagnosticFinding;
use Mquevedob\Provado\Patterns\DiagnosticFindingId;
use Mquevedob\Provado\Patterns\DiagnosticFindingSeverity;
use Mquevedob\Provado\Patterns\DiagnosticPattern;
use Mquevedob\Provado\Patterns\DiagnosticPatternRegistry;
use Mquevedob\Provado\Patterns\PatternEvaluationResult;
use PHPUnit\Framework\TestCase;
use stdClass;

class DiagnosticPatternTest extends TestCase
{
    public function test_pattern_evaluation_result_none(): void
    {
        $result = PatternEvaluationResult::none();

        $this->assertSame([], $result->findings());
        $this->assertFalse($result->hasFindings());
    }

    public function test_pattern_evaluation_result_from_findings(): void
    {
        $finding = $this->finding();

        $result = PatternEvaluationResult::fromFindings([$finding]);

        $this->assertSame([$finding], $result->findings());
        $this->assertTrue($result->hasFindings());
    }

    public function test_pattern_evaluation_result_from_findings_rejects_invalid_entries(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PatternEvaluationResult findings must be DiagnosticFinding instances.');

        PatternEvaluationResult::fromFindings([new stdClass()]);
    }

    public function test_diagnostic_finding_validates_required_text(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DiagnosticFinding title cannot be empty.');

        new DiagnosticFinding(
            id: new DiagnosticFindingId('finding-1'),
            patternId: 'pattern-1',
            title: '',
            summary: 'Checkout latency is elevated.',
            severity: DiagnosticFindingSeverity::warning(),
            correlationId: $this->group()->id,
            evidence: [],
            recommendedNextChecks: [],
        );
    }

    public function test_diagnostic_finding_stores_values_and_redacts_secret_evidence(): void
    {
        $correlationId = $this->group()->id;
        $findingId = new DiagnosticFindingId('finding-1');

        $finding = new DiagnosticFinding(
            id: $findingId,
            patternId: 'pattern-1',
            title: 'Checkout latency elevated',
            summary: 'Checkout latency is elevated.',
            severity: DiagnosticFindingSeverity::warning(),
            correlationId: $correlationId,
            evidence: [
                'service' => 'checkout',
                'api_key' => 'secret-value',
                'nested' => ['access_token' => 'nested-secret', 'safe' => 'kept'],
            ],
            recommendedNextChecks: ['Inspect recent checkout deployment.'],
        );

        $encodedEvidence = json_encode($finding->evidence, JSON_THROW_ON_ERROR);

        $this->assertSame($findingId, $finding->id);
        $this->assertSame('pattern-1', $finding->patternId);
        $this->assertSame('Checkout latency elevated', $finding->title);
        $this->assertSame('Checkout latency is elevated.', $finding->summary);
        $this->assertTrue($finding->severity->equals(DiagnosticFindingSeverity::warning()));
        $this->assertTrue($finding->correlationId->equals($correlationId));
        $this->assertSame('checkout', $finding->evidence['service']);
        $this->assertSame('[redacted]', $finding->evidence['api_key']);
        $this->assertSame('[redacted]', $finding->evidence['nested']['access_token']);
        $this->assertSame('kept', $finding->evidence['nested']['safe']);
        $this->assertSame(['Inspect recent checkout deployment.'], $finding->recommendedNextChecks);
        $this->assertStringNotContainsString('secret-value', $encodedEvidence);
        $this->assertStringNotContainsString('nested-secret', $encodedEvidence);
    }

    public function test_diagnostic_finding_id_generation_is_deterministic(): void
    {
        $correlationId = $this->group()->id;

        $first = DiagnosticFindingId::fromPatternAndCorrelation('pattern-1', $correlationId);
        $second = DiagnosticFindingId::fromPatternAndCorrelation('pattern-1', $correlationId);
        $differentPattern = DiagnosticFindingId::fromPatternAndCorrelation('pattern-2', $correlationId);

        $this->assertTrue($first->equals($second));
        $this->assertFalse($first->equals($differentPattern));
    }

    public function test_diagnostic_finding_severity_accepts_valid_values_and_compares_by_value(): void
    {
        $this->assertTrue(DiagnosticFindingSeverity::info()->equals(new DiagnosticFindingSeverity('info')));
        $this->assertTrue(DiagnosticFindingSeverity::warning()->equals(new DiagnosticFindingSeverity('warning')));
        $this->assertTrue(DiagnosticFindingSeverity::error()->equals(new DiagnosticFindingSeverity('error')));
        $this->assertTrue(DiagnosticFindingSeverity::critical()->equals(new DiagnosticFindingSeverity('critical')));
    }

    public function test_diagnostic_finding_severity_rejects_invalid_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DiagnosticFindingSeverity must be one of: info, warning, error, critical.');

        new DiagnosticFindingSeverity('notice');
    }

    public function test_diagnostic_pattern_registry_validates_pattern_entries(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Diagnostic pattern registry entries must implement DiagnosticPattern.');

        new DiagnosticPatternRegistry([new stdClass()]);
    }

    public function test_diagnostic_pattern_registry_rejects_duplicate_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate diagnostic pattern id "pattern-1".');

        new DiagnosticPatternRegistry([
            new FakeDiagnosticPattern('pattern-1', supported: true),
            new FakeDiagnosticPattern('pattern-1', supported: false),
        ]);
    }

    public function test_matching_returns_only_supported_patterns(): void
    {
        $supported = new FakeDiagnosticPattern('supported', supported: true);
        $unsupported = new FakeDiagnosticPattern('unsupported', supported: false);
        $registry = new DiagnosticPatternRegistry([$supported, $unsupported]);

        $this->assertSame(['supported' => $supported], $registry->matching($this->group()));
    }

    public function test_evaluate_evaluates_only_supported_patterns(): void
    {
        $supported = new FakeDiagnosticPattern('supported', supported: true, result: PatternEvaluationResult::none());
        $unsupported = new FakeDiagnosticPattern('unsupported', supported: false, result: PatternEvaluationResult::none());
        $registry = new DiagnosticPatternRegistry([$supported, $unsupported]);

        $results = $registry->evaluate($this->group());

        $this->assertCount(1, $results);
        $this->assertSame(1, $supported->evaluations);
        $this->assertSame(0, $unsupported->evaluations);
    }

    private function finding(): DiagnosticFinding
    {
        $group = $this->group();

        return new DiagnosticFinding(
            id: DiagnosticFindingId::fromPatternAndCorrelation('pattern-1', $group->id),
            patternId: 'pattern-1',
            title: 'Checkout latency elevated',
            summary: 'Checkout latency is elevated.',
            severity: DiagnosticFindingSeverity::warning(),
            correlationId: $group->id,
            evidence: ['service' => 'checkout'],
            recommendedNextChecks: ['Inspect recent checkout deployment.'],
        );
    }

    private function group(): CorrelationGroup
    {
        return new CorrelationGroup([
            $this->signal('signal-1'),
            $this->signal('signal-2'),
        ]);
    }

    private function signal(string $id): Signal
    {
        return new Signal(
            id: new SignalId($id),
            source: new SignalSource('new_relic'),
            type: new SignalType('latency_spike'),
            timestamp: new DateTimeImmutable('2026-06-08T12:00:00+00:00'),
            severity: SignalSeverity::warning(),
            entityReferences: [new EntityReference('service', 'checkout')],
            attributes: ['duration_ms' => 1250],
            rawPayloadReference: new RawPayloadReference('payload-1'),
        );
    }
}

final class FakeDiagnosticPattern implements DiagnosticPattern
{
    public int $evaluations = 0;

    public function __construct(
        private string $id,
        private bool $supported,
        private ?PatternEvaluationResult $result = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->id;
    }

    public function supports(CorrelationGroup $group): bool
    {
        return $this->supported;
    }

    public function evaluate(CorrelationGroup $group): PatternEvaluationResult
    {
        $this->evaluations++;

        return $this->result ?? PatternEvaluationResult::none();
    }
}
