<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Patterns\Catalog;

use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Correlation\CorrelationGroup;
use Mquevedob\Provado\Patterns\DiagnosticEvidence;
use Mquevedob\Provado\Patterns\DiagnosticFinding;
use Mquevedob\Provado\Patterns\DiagnosticFindingId;
use Mquevedob\Provado\Patterns\DiagnosticPattern;
use Mquevedob\Provado\Patterns\PatternEvaluationResult;

/**
 * Detects a catalog/feed sync failure: a failing product-feed import correlated
 * with downstream catalog impact (a stuck indexer or inventory drift). This
 * points at the catalog ingestion pipeline rather than the order pipeline that
 * OrderOperationsBacklogPattern covers.
 */
final readonly class CatalogFeedSyncFailurePattern implements DiagnosticPattern
{
    use DiagnosticEvidence;

    private const ADOBE_COMMERCE_SOURCE = 'adobe_commerce';

    private const FEED_SYNC_FAILURE_TYPE = 'catalog_feed_sync_failure';

    /**
     * @var list<string>
     */
    private const CATALOG_IMPACT_TYPES = [
        'indexer_stuck',
        'inventory_sync_drift',
    ];

    /**
     * @var list<string>
     */
    private const METRICS = [
        'feed_error_count',
        'drift_count',
        'stuck_duration_minutes',
    ];

    /**
     * @var list<string>
     */
    private const RECOMMENDED_NEXT_CHECKS = [
        'Inspect the product feed import logs for parse/validation errors.',
        'Check the feed source connectivity and recent catalog/price imports.',
        'Reindex affected indexers (e.g. catalogsearch_fulltext) once the feed is healthy.',
        'Validate inventory source and stock synchronization for drifted SKUs.',
    ];

    public function id(): string
    {
        return 'catalog_feed_sync_failure';
    }

    public function name(): string
    {
        return 'Catalog feed sync failure';
    }

    public function supports(CorrelationGroup $group): bool
    {
        $hasFeedSyncFailure = false;
        $hasCatalogImpact = false;

        foreach ($group->signals as $signal) {
            if ($this->isFeedSyncFailureSignal($signal)) {
                $hasFeedSyncFailure = true;
            }

            if ($this->isCatalogImpactSignal($signal)) {
                $hasCatalogImpact = true;
            }
        }

        return $hasFeedSyncFailure && $hasCatalogImpact;
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
                title: 'Catalog feed sync failure detected',
                summary: 'A failing catalog feed sync was correlated with downstream catalog impact (a stuck indexer or inventory drift) in the same signal group.',
                severity: $this->findingSeverity($group->highestSeverity()),
                correlationId: $group->id,
                evidence: $this->withMetricEvidence($this->baseEvidence($group), $group, self::METRICS),
                recommendedNextChecks: self::RECOMMENDED_NEXT_CHECKS,
            ),
        ]);
    }

    private function isFeedSyncFailureSignal(Signal $signal): bool
    {
        return $signal->source->value === self::ADOBE_COMMERCE_SOURCE
            && $signal->type->value === self::FEED_SYNC_FAILURE_TYPE;
    }

    private function isCatalogImpactSignal(Signal $signal): bool
    {
        return $signal->source->value === self::ADOBE_COMMERCE_SOURCE
            && in_array($signal->type->value, self::CATALOG_IMPACT_TYPES, true);
    }
}
