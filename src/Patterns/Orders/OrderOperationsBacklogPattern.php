<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Patterns\Orders;

use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Correlation\CorrelationGroup;
use Mquevedob\Provado\Patterns\DiagnosticEvidence;
use Mquevedob\Provado\Patterns\DiagnosticFinding;
use Mquevedob\Provado\Patterns\DiagnosticFindingId;
use Mquevedob\Provado\Patterns\DiagnosticPattern;
use Mquevedob\Provado\Patterns\PatternEvaluationResult;

/**
 * Detects back-office order/catalog processing degradation: an order export
 * backlog correlated with a stuck indexer. This is distinct from checkout
 * degradation — it points at fulfillment/catalog pipelines rather than the
 * customer-facing checkout flow.
 */
final readonly class OrderOperationsBacklogPattern implements DiagnosticPattern
{
    use DiagnosticEvidence;

    private const ADOBE_COMMERCE_SOURCE = 'adobe_commerce';

    private const ORDER_SYNC_BACKLOG_TYPE = 'order_sync_backlog';

    private const INDEXER_STUCK_TYPE = 'indexer_stuck';

    /**
     * @var list<string>
     */
    private const BACKLOG_METRICS = [
        'backlog_count',
        'stuck_duration_minutes',
        'drift_count',
    ];

    /**
     * @var list<string>
     */
    private const RECOMMENDED_NEXT_CHECKS = [
        'Check the order export/sync queue depth and consumer/worker health.',
        'Inspect stuck indexers and reindex if needed (e.g. catalogsearch_fulltext).',
        'Review recent catalog/price changes and cron schedule for stalled jobs.',
        'Validate inventory source and stock synchronization.',
    ];

    public function id(): string
    {
        return 'order_operations_backlog';
    }

    public function name(): string
    {
        return 'Order operations backlog';
    }

    public function supports(CorrelationGroup $group): bool
    {
        $hasOrderSyncBacklog = false;
        $hasIndexerStuck = false;

        foreach ($group->signals as $signal) {
            if ($this->isAdobeCommerceType($signal, self::ORDER_SYNC_BACKLOG_TYPE)) {
                $hasOrderSyncBacklog = true;
            }

            if ($this->isAdobeCommerceType($signal, self::INDEXER_STUCK_TYPE)) {
                $hasIndexerStuck = true;
            }
        }

        return $hasOrderSyncBacklog && $hasIndexerStuck;
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
                title: 'Order operations backlog detected',
                summary: 'An order export backlog and a stuck indexer were correlated in the same signal group, indicating back-office order/catalog processing is degraded.',
                severity: $this->findingSeverity($group->highestSeverity()),
                correlationId: $group->id,
                evidence: $this->evidence($group),
                recommendedNextChecks: self::RECOMMENDED_NEXT_CHECKS,
            ),
        ]);
    }

    private function isAdobeCommerceType(Signal $signal, string $type): bool
    {
        return $signal->source->value === self::ADOBE_COMMERCE_SOURCE
            && $signal->type->value === $type;
    }

    /**
     * @return array<string, mixed>
     */
    private function evidence(CorrelationGroup $group): array
    {
        return $this->withMetricEvidence($this->baseEvidence($group), $group, self::BACKLOG_METRICS);
    }
}
