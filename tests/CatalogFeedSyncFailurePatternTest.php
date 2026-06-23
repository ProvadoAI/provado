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
use Mquevedob\Provado\Patterns\Catalog\CatalogFeedSyncFailurePattern;
use Mquevedob\Provado\Patterns\DiagnosticFindingId;
use Mquevedob\Provado\Patterns\DiagnosticFindingSeverity;
use Mquevedob\Provado\Sources\AdobeCommerce\AdobeCommerceAdapter;
use PHPUnit\Framework\TestCase;

class CatalogFeedSyncFailurePatternTest extends TestCase
{
    public function test_id_and_name(): void
    {
        $pattern = new CatalogFeedSyncFailurePattern();

        $this->assertSame('catalog_feed_sync_failure', $pattern->id());
        $this->assertSame('Catalog feed sync failure', $pattern->name());
    }

    public function test_supports_false_when_only_feed_failure_exists(): void
    {
        $group = new CorrelationGroup([$this->feedSyncFailureSignal('a')]);

        $this->assertFalse((new CatalogFeedSyncFailurePattern())->supports($group));
    }

    public function test_supports_false_when_only_catalog_impact_exists(): void
    {
        $group = new CorrelationGroup([$this->indexerStuckSignal('a')]);

        $this->assertFalse((new CatalogFeedSyncFailurePattern())->supports($group));
    }

    public function test_supports_true_with_stuck_indexer(): void
    {
        $group = new CorrelationGroup([
            $this->feedSyncFailureSignal('a'),
            $this->indexerStuckSignal('b'),
        ]);

        $this->assertTrue((new CatalogFeedSyncFailurePattern())->supports($group));
    }

    public function test_supports_true_with_inventory_drift(): void
    {
        $group = new CorrelationGroup([
            $this->feedSyncFailureSignal('a'),
            $this->inventoryDriftSignal('b'),
        ]);

        $this->assertTrue((new CatalogFeedSyncFailurePattern())->supports($group));
    }

    public function test_evaluate_unsupported_group_returns_none(): void
    {
        $result = (new CatalogFeedSyncFailurePattern())->evaluate(
            new CorrelationGroup([$this->feedSyncFailureSignal('a')]),
        );

        $this->assertFalse($result->hasFindings());
    }

    public function test_evaluate_supported_group_returns_one_finding(): void
    {
        $group = $this->supportedGroup();
        $result = (new CatalogFeedSyncFailurePattern())->evaluate($group);

        $this->assertCount(1, $result->findings());
        $this->assertSame('Catalog feed sync failure detected', $result->findings()[0]->title);
        $this->assertSame('catalog_feed_sync_failure', $result->findings()[0]->patternId);
        $this->assertTrue($result->findings()[0]->correlationId->equals($group->id));
    }

    public function test_finding_id_is_deterministic(): void
    {
        $pattern = new CatalogFeedSyncFailurePattern();
        $group = $this->supportedGroup();
        $finding = $pattern->evaluate($group)->findings()[0];

        $this->assertTrue($finding->id->equals(DiagnosticFindingId::fromPatternAndCorrelation($pattern->id(), $group->id)));
    }

    public function test_finding_severity_follows_highest_group_severity(): void
    {
        $finding = (new CatalogFeedSyncFailurePattern())->evaluate(new CorrelationGroup([
            $this->feedSyncFailureSignal('a', severity: SignalSeverity::error()),
            $this->indexerStuckSignal('b', severity: SignalSeverity::critical()),
        ]))->findings()[0];

        $this->assertTrue($finding->severity->equals(DiagnosticFindingSeverity::critical()));
    }

    public function test_finding_evidence_contains_base_fields_and_metrics(): void
    {
        $finding = (new CatalogFeedSyncFailurePattern())->evaluate($this->supportedGroup())->findings()[0];

        $this->assertSame(['adobe_commerce'], $finding->evidence['involved_sources']);
        $this->assertSame(['catalog_feed_sync_failure', 'indexer_stuck'], $finding->evidence['involved_types']);
        $this->assertSame(145, $finding->evidence['feed_error_count']);
        $this->assertSame(92, $finding->evidence['stuck_duration_minutes']);
    }

    public function test_pattern_is_diagnosable_from_fixtures(): void
    {
        $result = (new AdobeCommerceAdapter())->fetch(
            new SourceConfig(
                name: 'adobe_commerce',
                enabled: true,
                options: ['fixtures' => ['catalog_feed_sync_failure', 'inventory_sync_drift']],
                credentials: new SourceCredentials(),
            ),
            new TimeWindow(
                start: new DateTimeImmutable('2026-06-08T12:00:00+00:00'),
                end: new DateTimeImmutable('2026-06-08T12:30:00+00:00'),
            ),
        );

        $this->assertCount(2, $result->signals());

        $group = new CorrelationGroup($result->signals());
        $finding = (new CatalogFeedSyncFailurePattern())->evaluate($group)->findings()[0];

        $this->assertSame('catalog_feed_sync_failure', $finding->patternId);
        $this->assertSame(145, $finding->evidence['feed_error_count']);
        $this->assertSame(37, $finding->evidence['drift_count']);
    }

    private function supportedGroup(): CorrelationGroup
    {
        return new CorrelationGroup([
            $this->feedSyncFailureSignal('a'),
            $this->indexerStuckSignal('b'),
        ]);
    }

    private function feedSyncFailureSignal(string $id, ?SignalSeverity $severity = null): Signal
    {
        return new Signal(
            id: new SignalId('adobe_commerce:'.$id),
            source: new SignalSource('adobe_commerce'),
            type: new SignalType('catalog_feed_sync_failure'),
            timestamp: new DateTimeImmutable('2026-06-08T12:08:00+00:00'),
            severity: $severity ?? SignalSeverity::error(),
            entityReferences: [
                new EntityReference('store', 'default'),
                new EntityReference('feed', 'product_catalog'),
            ],
            attributes: ['feed_error_count' => 145],
            rawPayloadReference: new RawPayloadReference($id),
        );
    }

    private function indexerStuckSignal(string $id, ?SignalSeverity $severity = null): Signal
    {
        return new Signal(
            id: new SignalId('adobe_commerce:'.$id),
            source: new SignalSource('adobe_commerce'),
            type: new SignalType('indexer_stuck'),
            timestamp: new DateTimeImmutable('2026-06-08T12:15:00+00:00'),
            severity: $severity ?? SignalSeverity::critical(),
            entityReferences: [
                new EntityReference('store', 'default'),
                new EntityReference('indexer', 'catalogsearch_fulltext'),
            ],
            attributes: ['stuck_duration_minutes' => 92],
            rawPayloadReference: new RawPayloadReference($id),
        );
    }

    private function inventoryDriftSignal(string $id, ?SignalSeverity $severity = null): Signal
    {
        return new Signal(
            id: new SignalId('adobe_commerce:'.$id),
            source: new SignalSource('adobe_commerce'),
            type: new SignalType('inventory_sync_drift'),
            timestamp: new DateTimeImmutable('2026-06-08T12:10:00+00:00'),
            severity: $severity ?? SignalSeverity::critical(),
            entityReferences: [
                new EntityReference('store', 'default'),
                new EntityReference('sku', '24-MB01'),
            ],
            attributes: ['drift_count' => 37],
            rawPayloadReference: new RawPayloadReference($id),
        );
    }
}
