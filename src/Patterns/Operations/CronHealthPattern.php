<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Patterns\Operations;

use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Correlation\CorrelationGroup;
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

    /** A pending backlog above this is treated as cron not keeping up. */
    private const PENDING_BACKLOG = 100;

    /** An elevated count of errored cron rows. */
    private const ERROR_ELEVATED = 500;

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

        $missed = $this->metric($cron, 'missed');
        $pending = $this->metric($cron, 'pending');
        $error = $this->metric($cron, 'error');
        $running = $this->metric($cron, 'running');

        // Healthy cron: nothing missed, backlog and errors within bounds.
        if ($missed === 0 && $pending <= self::PENDING_BACKLOG && $error <= self::ERROR_ELEVATED) {
            return PatternEvaluationResult::none();
        }

        $downstream = $this->downstreamSymptoms($group);

        return PatternEvaluationResult::fromFindings([
            new DiagnosticFinding(
                id: DiagnosticFindingId::fromPatternAndCorrelation($this->id(), $group->id),
                patternId: $this->id(),
                title: 'Cron health degraded',
                summary: $this->summary($missed, $pending, $error, $downstream),
                severity: $this->severity($missed, $pending, $error),
                correlationId: $group->id,
                evidence: $this->evidence($group, $cron, $missed, $pending, $error, $running, $downstream),
                recommendedNextChecks: self::RECOMMENDED_NEXT_CHECKS,
            ),
        ]);
    }

    /**
     * @param list<string> $downstream
     */
    private function summary(int $missed, int $pending, int $error, array $downstream): string
    {
        $state = sprintf('%d pending, %d missed, %d errored cron rows', $pending, $missed, $error);

        if ($downstream === []) {
            return sprintf('Cron is degraded (%s) with no downstream symptoms observed yet.', $state);
        }

        return sprintf(
            'Cron is degraded (%s); the following downstream symptoms are attributed to it: %s.',
            $state,
            implode(', ', $downstream),
        );
    }

    private function severity(int $missed, int $pending, int $error): DiagnosticFindingSeverity
    {
        if ($missed > 0) {
            // The scheduler is not firing — the worst cron failure.
            return DiagnosticFindingSeverity::critical();
        }

        if ($pending > self::PENDING_BACKLOG || $error > self::ERROR_ELEVATED) {
            return DiagnosticFindingSeverity::error();
        }

        return DiagnosticFindingSeverity::warning();
    }

    /**
     * Downstream symptoms that co-occur with the cron degradation and are
     * collapsed into this one verdict instead of separate alerts.
     *
     * @return list<string>
     */
    private function downstreamSymptoms(CorrelationGroup $group): array
    {
        $symptoms = [];

        foreach ($group->signals as $signal) {
            if ($signal->type->value === self::INDEXER_STATUS
                && ($this->metric($signal, 'backlog') > 0 || $this->metric($signal, 'invalid') > 0)) {
                $symptoms[] = 'indexer '.$this->entityId($signal, 'indexer');
            }

            if ($signal->type->value === self::QUEUE_BACKLOG
                && $this->metric($signal, 'ready') > 0 && $this->metric($signal, 'consumers') === 0) {
                $symptoms[] = 'queue '.$this->entityId($signal, 'queue');
            }
        }

        return array_values(array_unique($symptoms));
    }

    /**
     * @param list<string> $downstream
     * @return array<string, mixed>
     */
    private function evidence(
        CorrelationGroup $group,
        Signal $cron,
        int $missed,
        int $pending,
        int $error,
        int $running,
        array $downstream,
    ): array {
        $evidence = $this->baseEvidence($group);
        $evidence['cron'] = [
            'pending' => $pending,
            'running' => $running,
            'missed' => $missed,
            'error' => $error,
        ];
        $evidence['cron_dwell_seconds'] = $this->dwellSeconds($group);
        $evidence['downstream_symptoms'] = $downstream;

        $config = $this->latestOfType($group, self::CONFIG_CHANGE);

        if ($config !== null) {
            $age = $this->metric($config, 'latest_change_age_seconds');
            $evidence['recent_config_change'] = $this->metric($config, 'changed_1h') > 0
                || ($age > 0 && $age <= self::RECENT_CONFIG_CHANGE_SECONDS);
            $evidence['latest_config_change_age_seconds'] = $age;
        }

        return $evidence;
    }

    /**
     * How long the cron signal has been observed across the window (latest minus
     * earliest cron_health timestamp) — a basic dwell proxy.
     */
    private function dwellSeconds(CorrelationGroup $group): int
    {
        $timestamps = [];

        foreach ($group->signals as $signal) {
            if ($signal->type->value === self::CRON_HEALTH) {
                $timestamps[] = $signal->timestamp->getTimestamp();
            }
        }

        if ($timestamps === []) {
            return 0;
        }

        return max($timestamps) - min($timestamps);
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
