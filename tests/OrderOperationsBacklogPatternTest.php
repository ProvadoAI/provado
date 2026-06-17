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
use Mquevedob\Provado\Patterns\DiagnosticFindingId;
use Mquevedob\Provado\Patterns\DiagnosticFindingSeverity;
use Mquevedob\Provado\Patterns\Orders\OrderOperationsBacklogPattern;
use PHPUnit\Framework\TestCase;

class OrderOperationsBacklogPatternTest extends TestCase
{
    public function test_id_and_name(): void
    {
        $pattern = new OrderOperationsBacklogPattern();

        $this->assertSame('order_operations_backlog', $pattern->id());
        $this->assertSame('Order operations backlog', $pattern->name());
    }

    public function test_supports_false_with_only_order_sync_backlog(): void
    {
        $pattern = new OrderOperationsBacklogPattern();
        $group = new CorrelationGroup([
            $this->adobeCommerceSignal('ac-1', 'order_sync_backlog'),
        ]);

        $this->assertFalse($pattern->supports($group));
    }

    public function test_supports_false_with_only_indexer_stuck(): void
    {
        $pattern = new OrderOperationsBacklogPattern();
        $group = new CorrelationGroup([
            $this->adobeCommerceSignal('ac-1', 'indexer_stuck'),
        ]);

        $this->assertFalse($pattern->supports($group));
    }

    public function test_supports_false_when_signals_are_not_adobe_commerce(): void
    {
        $pattern = new OrderOperationsBacklogPattern();
        $group = new CorrelationGroup([
            $this->signal('nr-1', new SignalSource('new_relic'), new SignalType('order_sync_backlog')),
            $this->signal('nr-2', new SignalSource('new_relic'), new SignalType('indexer_stuck')),
        ]);

        $this->assertFalse($pattern->supports($group));
    }

    public function test_supports_true_when_order_sync_backlog_and_indexer_stuck_are_correlated(): void
    {
        $this->assertTrue((new OrderOperationsBacklogPattern())->supports($this->supportedGroup()));
    }

    public function test_evaluate_unsupported_group_returns_none(): void
    {
        $result = (new OrderOperationsBacklogPattern())->evaluate(new CorrelationGroup([
            $this->adobeCommerceSignal('ac-1', 'order_sync_backlog'),
        ]));

        $this->assertFalse($result->hasFindings());
        $this->assertSame([], $result->findings());
    }

    public function test_evaluate_supported_group_returns_one_finding(): void
    {
        $group = $this->supportedGroup();
        $result = (new OrderOperationsBacklogPattern())->evaluate($group);

        $this->assertTrue($result->hasFindings());
        $this->assertCount(1, $result->findings());
        $this->assertSame('Order operations backlog detected', $result->findings()[0]->title);
        $this->assertSame('order_operations_backlog', $result->findings()[0]->patternId);
        $this->assertTrue($result->findings()[0]->correlationId->equals($group->id));
    }

    public function test_finding_id_is_deterministic(): void
    {
        $pattern = new OrderOperationsBacklogPattern();
        $group = $this->supportedGroup();
        $finding = $pattern->evaluate($group)->findings()[0];

        $this->assertTrue($finding->id->equals(DiagnosticFindingId::fromPatternAndCorrelation($pattern->id(), $group->id)));
        $this->assertTrue($finding->id->equals($pattern->evaluate($group)->findings()[0]->id));
    }

    public function test_finding_severity_follows_highest_group_severity(): void
    {
        $pattern = new OrderOperationsBacklogPattern();

        $criticalFinding = $pattern->evaluate(new CorrelationGroup([
            $this->adobeCommerceSignal('ac-backlog', 'order_sync_backlog', severity: SignalSeverity::warning()),
            $this->adobeCommerceSignal('ac-indexer', 'indexer_stuck', severity: SignalSeverity::critical()),
        ]))->findings()[0];
        $errorFinding = $pattern->evaluate(new CorrelationGroup([
            $this->adobeCommerceSignal('ac-backlog', 'order_sync_backlog', severity: SignalSeverity::error()),
            $this->adobeCommerceSignal('ac-indexer', 'indexer_stuck', severity: SignalSeverity::warning()),
        ]))->findings()[0];
        $warningFinding = $pattern->evaluate(new CorrelationGroup([
            $this->adobeCommerceSignal('ac-backlog', 'order_sync_backlog', severity: SignalSeverity::warning()),
            $this->adobeCommerceSignal('ac-indexer', 'indexer_stuck', severity: SignalSeverity::warning()),
        ]))->findings()[0];

        $this->assertTrue($criticalFinding->severity->equals(DiagnosticFindingSeverity::critical()));
        $this->assertTrue($errorFinding->severity->equals(DiagnosticFindingSeverity::error()));
        $this->assertTrue($warningFinding->severity->equals(DiagnosticFindingSeverity::warning()));
    }

    public function test_finding_evidence_contains_sources_types_shared_entities_timestamps_and_metrics(): void
    {
        $group = $this->supportedGroup();
        $finding = (new OrderOperationsBacklogPattern())->evaluate($group)->findings()[0];

        $this->assertSame($group->id->value, $finding->evidence['correlation_id']);
        $this->assertSame(['adobe_commerce'], $finding->evidence['involved_sources']);
        $this->assertSame(['order_sync_backlog', 'indexer_stuck'], $finding->evidence['involved_types']);
        $this->assertSame([['type' => 'store', 'id' => 'default']], $finding->evidence['shared_entities']);
        $this->assertSame('2026-06-08T12:05:00+00:00', $finding->evidence['starts_at']);
        $this->assertSame('2026-06-08T12:15:00+00:00', $finding->evidence['ends_at']);
        $this->assertSame('critical', $finding->evidence['highest_signal_severity']);
        $this->assertSame(218, $finding->evidence['backlog_count']);
        $this->assertSame(92, $finding->evidence['stuck_duration_minutes']);
    }

    public function test_finding_recommended_next_checks_are_present(): void
    {
        $finding = (new OrderOperationsBacklogPattern())->evaluate($this->supportedGroup())->findings()[0];

        $this->assertSame([
            'Check the order export/sync queue depth and consumer/worker health.',
            'Inspect stuck indexers and reindex if needed (e.g. catalogsearch_fulltext).',
            'Review recent catalog/price changes and cron schedule for stalled jobs.',
            'Validate inventory source and stock synchronization.',
        ], $finding->recommendedNextChecks);
    }

    private function supportedGroup(): CorrelationGroup
    {
        return new CorrelationGroup([
            $this->adobeCommerceSignal('ac-backlog', 'order_sync_backlog', severity: SignalSeverity::warning(), timestamp: new DateTimeImmutable('2026-06-08T12:05:00+00:00'), attributes: ['backlog_count' => 218], secondaryEntity: new EntityReference('queue', 'sales_order_export')),
            $this->adobeCommerceSignal('ac-indexer', 'indexer_stuck', severity: SignalSeverity::critical(), timestamp: new DateTimeImmutable('2026-06-08T12:15:00+00:00'), attributes: ['stuck_duration_minutes' => 92], secondaryEntity: new EntityReference('indexer', 'catalogsearch_fulltext')),
        ]);
    }

    /**
     * @param array<string, int|float> $attributes
     */
    private function adobeCommerceSignal(
        string $id,
        string $type,
        ?SignalSeverity $severity = null,
        ?DateTimeImmutable $timestamp = null,
        array $attributes = ['backlog_count' => 218],
        ?EntityReference $secondaryEntity = null,
    ): Signal {
        return new Signal(
            id: new SignalId($id),
            source: new SignalSource('adobe_commerce'),
            type: new SignalType($type),
            timestamp: $timestamp ?? new DateTimeImmutable('2026-06-08T12:05:00+00:00'),
            severity: $severity ?? SignalSeverity::warning(),
            entityReferences: [
                new EntityReference('store', 'default'),
                $secondaryEntity ?? new EntityReference('queue', 'sales_order_export'),
            ],
            attributes: $attributes,
            rawPayloadReference: new RawPayloadReference($id),
        );
    }

    private function signal(string $id, SignalSource $source, SignalType $type): Signal
    {
        return new Signal(
            id: new SignalId($id),
            source: $source,
            type: $type,
            timestamp: new DateTimeImmutable('2026-06-08T12:05:00+00:00'),
            severity: SignalSeverity::warning(),
            entityReferences: [new EntityReference('store', 'default')],
            attributes: ['backlog_count' => 218],
            rawPayloadReference: new RawPayloadReference($id),
        );
    }
}
