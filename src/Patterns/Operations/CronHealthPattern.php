<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Patterns\Operations;

use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Correlation\CorrelationGroup;
use Mquevedob\Provado\Diagnosis\DependencyEdge;
use Mquevedob\Provado\Diagnosis\DependencyGraph;
use Mquevedob\Provado\Diagnosis\SignalSeries;
use Mquevedob\Provado\Patterns\DiagnosticEvidence;
use Mquevedob\Provado\Patterns\DiagnosticFinding;
use Mquevedob\Provado\Patterns\DiagnosticFindingId;
use Mquevedob\Provado\Patterns\DiagnosticFindingSeverity;
use Mquevedob\Provado\Patterns\DiagnosticPattern;
use Mquevedob\Provado\Patterns\PatternEvaluationResult;

/**
 * First operational-state diagnosis (v0.5.0): cron health, with dependency-graph
 * collapse. Cron is upstream of reindexing, cache cleanup, queue consumers and
 * email; a degraded cron is one root cause that makes index/queue go stale at
 * once. When the cron signal is unhealthy this emits a single verdict and folds
 * any co-occurring downstream symptoms (indexer backlog/invalid, stalled queues)
 * into it — rather than N separate alerts — and stamps it against the
 * config-change timeline.
 *
 * Reads the `ProvadoSignal`-shipped operational signals (see docs/signal-shipping.md):
 * cron_health / indexer_status / queue_backlog / config_change.
 */
final readonly class CronHealthPattern implements DiagnosticPattern
{
    use DiagnosticEvidence;

    private const CRON_HEALTH = 'cron_health';

    private const INDEXER_STATUS = 'indexer_status';

    private const QUEUE_BACKLOG = 'queue_backlog';

    private const CONFIG_CHANGE = 'config_change';

    /**
     * Cold-start floor for the pending backlog: used only until the learned
     * baseline has enough samples to judge "high" relative to this instance's
     * normal (see MIN_BASELINE_SAMPLES).
     */
    private const PENDING_BACKLOG = 100;

    /** Cold-start floor for errored cron rows (see PENDING_BACKLOG). */
    private const ERROR_ELEVATED = 500;

    /**
     * Snapshots required before the learned baseline is trusted over the static
     * cold-start floor. Below this the series is too short to characterise normal.
     */
    private const MIN_BASELINE_SAMPLES = 4;

    /**
     * A normal cron job finishes within a poll or two; `running > 0` persisting
     * past this flags a *possible* stuck worker (a scheduled task reporting
     * "running" while processing nothing — the ACSD-51431 shape), distinct from
     * `missed` (scheduler not firing) and `pending` (backlog).
     *
     * Caveat — `running` is a count, not a per-job identity: a continuously-busy
     * instance with a steady stream of short jobs can also keep the count non-zero
     * across the window. So this is a candidate to inspect, not a certainty; the
     * verdict and summary hedge accordingly. A per-job running-age signal (shipper
     * change) would make it definitive — deferred.
     */
    private const STUCK_RUNNING_SECONDS = 900;

    /** A config change within this many seconds of the cron signal is "recent". */
    private const RECENT_CONFIG_CHANGE_SECONDS = 3600;

    /**
     * @var list<string>
     */
    private const RECOMMENDED_NEXT_CHECKS = [
        'Inspect cron_schedule for missed/stuck jobs and the consumers_runner job.',
        'Confirm the cron daemon is running and not backed up.',
        'Check downstream effects: indexer backlog, stalled queues, unsent emails.',
        'Correlate the onset with the nearest deploy or core_config_data change.',
    ];

    public function id(): string
    {
        return 'cron_health';
    }

    public function name(): string
    {
        return 'Cron health degradation';
    }

    public function supports(CorrelationGroup $group): bool
    {
        return $this->latestOfType($group, self::CRON_HEALTH) !== null;
    }

    public function evaluate(CorrelationGroup $group): PatternEvaluationResult
    {
        $cron = $this->latestOfType($group, self::CRON_HEALTH);

        if ($cron === null) {
            return PatternEvaluationResult::none();
        }

        $series = new SignalSeries($group->signals);

        $missed = $this->metric($cron, 'missed');
        $pending = $this->metric($cron, 'pending');
        $error = $this->metric($cron, 'error');
        $running = $this->metric($cron, 'running');

        $pendingElevated = $this->isMetricElevated($series, $cron, 'pending', self::PENDING_BACKLOG);
        $errorElevated = $this->isMetricElevated($series, $cron, 'error', self::ERROR_ELEVATED);
        $runningDwell = $this->runningDwellSeconds($series, $cron);
        $runningStuck = $runningDwell >= self::STUCK_RUNNING_SECONDS;

        // Healthy cron: nothing missed, backlog/errors within learned-normal (or the
        // cold-start floor), and no worker stuck running past its expected runtime.
        if ($missed === 0 && ! $pendingElevated && ! $errorElevated && ! $runningStuck) {
            return PatternEvaluationResult::none();
        }

        $downstream = $this->downstreamSymptoms($series);
        $downstreamLabels = array_column($downstream, 'label');

        return PatternEvaluationResult::fromFindings([
            new DiagnosticFinding(
                id: DiagnosticFindingId::fromPatternAndCorrelation($this->id(), $group->id),
                patternId: $this->id(),
                title: 'Cron health degraded',
                summary: $this->summary($missed, $pending, $error, $running, $runningStuck, $runningDwell, $downstreamLabels),
                severity: $this->severity($missed, $pendingElevated, $errorElevated, $runningStuck),
                correlationId: $group->id,
                evidence: $this->evidence($group, $series, $cron, $missed, $pending, $error, $running, $runningDwell, $runningStuck, $downstream),
                recommendedNextChecks: self::RECOMMENDED_NEXT_CHECKS,
            ),
        ]);
    }

    /**
     * How long `running` has been continuously above zero, ending at the latest
     * snapshot — the dwell that distinguishes a worker stuck mid-job from a job
     * that is merely busy right now. 0 when nothing is running.
     */
    private function runningDwellSeconds(SignalSeries $series, Signal $cron): int
    {
        if ($this->metric($cron, 'running') <= 0) {
            return 0;
        }

        return $series->dwellSeconds($cron, fn (Signal $s): bool => $this->metric($s, 'running') > 0);
    }

    /**
     * @param list<string> $downstream
     */
    private function summary(int $missed, int $pending, int $error, int $running, bool $runningStuck, int $runningDwell, array $downstream): string
    {
        $state = sprintf('%d pending, %d missed, %d errored cron rows', $pending, $missed, $error);

        if ($runningStuck) {
            $state .= sprintf(', running held at %d for %ds (possible stuck worker)', $running, $runningDwell);
        }

        if ($downstream === []) {
            return sprintf('Cron is degraded (%s) with no downstream symptoms observed yet.', $state);
        }

        return sprintf(
            'Cron is degraded (%s); the following downstream symptoms are attributed to it: %s.',
            $state,
            implode(', ', $downstream),
        );
    }

    /**
     * Cron is unhealthy when the scheduler is missing jobs, or the pending backlog
     * or errored rows are high relative to this instance's learned-normal (falling
     * back to the static cold-start floor on a short series). Reused as the "is
     * this state bad?" predicate for dwell, evaluated against the same series.
     */
    private function isCronUnhealthy(Signal $cron, SignalSeries $series): bool
    {
        return $this->metric($cron, 'missed') > 0
            || $this->isMetricElevated($series, $cron, 'pending', self::PENDING_BACKLOG)
            || $this->isMetricElevated($series, $cron, 'error', self::ERROR_ELEVATED);
    }

    /**
     * A metric reads high when it exceeds the learned-normal upper bound — once the
     * series is long enough to characterise normal — otherwise when it exceeds the
     * static cold-start floor. This is what lets a stably-high-but-constant metric
     * read normal while a real spike or a drift above a low normal is caught.
     */
    private function isMetricElevated(SignalSeries $series, Signal $cron, string $metric, int $floor): bool
    {
        $value = $this->metric($cron, $metric);
        $baseline = $series->baselineFor($cron, $metric);

        if ($baseline->sampleCount() >= self::MIN_BASELINE_SAMPLES) {
            return $baseline->isHigh($value);
        }

        return $value > $floor;
    }

    private function severity(int $missed, bool $pendingElevated, bool $errorElevated, bool $runningStuck): DiagnosticFindingSeverity
    {
        if ($missed > 0) {
            // The scheduler is not firing — the worst cron failure.
            return DiagnosticFindingSeverity::critical();
        }

        if ($pendingElevated || $errorElevated || $runningStuck) {
            return DiagnosticFindingSeverity::error();
        }

        return DiagnosticFindingSeverity::warning();
    }

    /**
     * Surface why the verdict fired: how many snapshots the baseline saw, whether
     * it was trusted (vs the cold-start floor), and the learned high-water mark for
     * the pending/error metrics.
     *
     * @return array<string, mixed>
     */
    private function baselineEvidence(SignalSeries $series, Signal $cron): array
    {
        $pending = $series->baselineFor($cron, 'pending');
        $error = $series->baselineFor($cron, 'error');

        return [
            'samples' => $pending->sampleCount(),
            'learned' => $pending->sampleCount() >= self::MIN_BASELINE_SAMPLES,
            'pending_high' => round($pending->high(), 1),
            'error_high' => round($error->high(), 1),
        ];
    }

    /**
     * Downstream symptoms that co-occur with the cron degradation and collapse into
     * this one verdict instead of separate alerts. Attribution is driven by the
     * explicit dependency graph (no per-type conditional) and is dwell-qualified —
     * each symptom carries how long it has persisted.
     *
     * @return list<array{label: string, dwell_seconds: int}>
     */
    private function downstreamSymptoms(SignalSeries $series): array
    {
        $graph = $this->cronDependencyGraph();
        $symptoms = [];

        // Reduce to current state per entity first: with continuous shipping the
        // group holds many snapshots per indexer/queue, and a stale bad snapshot
        // must not flag an entity whose latest reading is healthy.
        foreach ($series->latestPerEntity() as $signal) {
            $edge = $graph->edgeForSignalType($signal->type->value);

            if ($edge === null || ! $this->isSymptomatic($edge, $signal)) {
                continue;
            }

            $label = $edge->entityType.' '.$this->entityId($signal, $edge->entityType);

            $symptoms[$label] = [
                'label' => $label,
                'dwell_seconds' => $series->dwellSeconds(
                    $signal,
                    fn (Signal $s): bool => $this->isSymptomatic($edge, $s),
                ),
            ];
        }

        return array_values($symptoms);
    }

    /**
     * The Magento dependency graph rooted at cron: cron is upstream of cache,
     * index, email and queue (the architecture doc's lead pattern). Index and queue
     * are lit — their `ProvadoSignal`s ship today. Cache and email are declared but
     * dark: they await an "Instrument" signal (cache invalidation / consumer
     * liveness) and light up by supplying it, with no graph redesign.
     */
    private function cronDependencyGraph(): DependencyGraph
    {
        return new DependencyGraph('cron', [
            DependencyEdge::lit('index', 'indexer', self::INDEXER_STATUS),
            DependencyEdge::lit('queue', 'queue', self::QUEUE_BACKLOG),
            DependencyEdge::dark('cache', 'cache'),
            DependencyEdge::dark('email', 'consumer'),
        ]);
    }

    /**
     * Whether a downstream signal is currently symptomatic for its edge. Edge
     * semantics: an indexer in backlog or invalid; a queue with work ready but no
     * consumer draining it (disconnected). A newly-lit edge adds its arm here.
     */
    private function isSymptomatic(DependencyEdge $edge, Signal $signal): bool
    {
        return match ($edge->node) {
            'index' => $this->metric($signal, 'backlog') > 0 || $this->metric($signal, 'invalid') > 0,
            'queue' => $this->metric($signal, 'ready') > 0 && $this->metric($signal, 'consumers') === 0,
            default => false,
        };
    }

    /**
     * @param list<array{label: string, dwell_seconds: int}> $downstream
     * @return array<string, mixed>
     */
    private function evidence(
        CorrelationGroup $group,
        SignalSeries $series,
        Signal $cron,
        int $missed,
        int $pending,
        int $error,
        int $running,
        int $runningDwell,
        bool $runningStuck,
        array $downstream,
    ): array {
        $graph = $this->cronDependencyGraph();

        $evidence = $this->baseEvidence($group);
        $evidence['cron'] = [
            'pending' => $pending,
            'running' => $running,
            'missed' => $missed,
            'error' => $error,
        ];
        $evidence['cron_dwell_seconds'] = $series->dwellSeconds(
            $cron,
            fn (Signal $s): bool => $this->isCronUnhealthy($s, $series),
        );
        $evidence['running_dwell_seconds'] = $runningDwell;
        $evidence['running_stuck'] = $runningStuck;
        $evidence['cron_baseline'] = $this->baselineEvidence($series, $cron);
        $evidence['downstream_symptoms'] = array_column($downstream, 'label');
        $evidence['downstream_dwell_seconds'] = array_column($downstream, 'dwell_seconds', 'label');
        // Surface the whole graph, including edges declared but not yet fed by a
        // signal (cache/email), so the collapse's coverage is explicit.
        $evidence['dependency_graph'] = [
            'upstream' => $graph->upstream,
            'lit' => $graph->litEdges(),
            'dark' => $graph->darkEdges(),
        ];

        $config = $this->latestOfType($group, self::CONFIG_CHANGE);

        if ($config !== null) {
            $age = $this->metric($config, 'latest_change_age_seconds');
            $evidence['recent_config_change'] = $this->metric($config, 'changed_1h') > 0
                || ($age > 0 && $age <= self::RECENT_CONFIG_CHANGE_SECONDS);
            $evidence['latest_config_change_age_seconds'] = $age;
        }

        return $evidence;
    }

    private function latestOfType(CorrelationGroup $group, string $type): ?Signal
    {
        $latest = null;

        foreach ($group->signals as $signal) {
            if ($signal->type->value !== $type) {
                continue;
            }

            if ($latest === null || $signal->timestamp > $latest->timestamp) {
                $latest = $signal;
            }
        }

        return $latest;
    }

    private function metric(Signal $signal, string $name): int
    {
        $value = $signal->attributes[$name] ?? 0;

        return is_int($value) || is_float($value) ? (int) $value : 0;
    }

    private function entityId(Signal $signal, string $type): string
    {
        foreach ($signal->entityReferences as $entity) {
            if ($entity->type === $type) {
                return $entity->id;
            }
        }

        return 'unknown';
    }
}
