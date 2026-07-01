<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Diagnosis;

use Mquevedob\Provado\Core\EntityReference;
use Mquevedob\Provado\Core\Signal;

/**
 * The diagnosis layer's view of a set of signals as a time series.
 *
 * The correlation substrate (CorrelationEngine) is a pure time + entity join; it
 * deliberately knows nothing about state-over-time. But a continuously-shipping
 * source emits one snapshot per poll, so a correlation window holds many snapshots
 * per `(signal, entity)`. The diagnosis layer needs two things the raw pile cannot
 * give: the *current* state (the latest snapshot per entity) and *dwell* (how long
 * the current state has persisted). This value object provides both, leaving the
 * substrate untouched — "ingestion is not diagnosis"
 * (docs/ARCHITECTURE_DIRECTION_SOURCE.md, Part D).
 *
 * An entity series is keyed by signal type plus the full entity-reference set, so
 * two polls of the same indexer view collapse together while distinct views (or
 * queues) stay separate.
 */
final readonly class SignalSeries
{
    /**
     * @param list<Signal> $signals
     */
    public function __construct(public array $signals)
    {
    }

    /**
     * Collapse the series to the most recent snapshot per `(type, entity-set)`, so
     * patterns reason over current state instead of a pile of per-poll duplicates.
     * First-seen order is preserved; the latest snapshot (by timestamp) wins within
     * each key.
     *
     * @return list<Signal>
     */
    public function latestPerEntity(): array
    {
        $latestByKey = [];

        foreach ($this->signals as $signal) {
            $key = $this->key($signal);

            if (! isset($latestByKey[$key]) || $signal->timestamp > $latestByKey[$key]->timestamp) {
                $latestByKey[$key] = $signal;
            }
        }

        return array_values($latestByKey);
    }

    /**
     * How long the entity series matching `$reference` has been continuously "bad"
     * (per `$isBad`), measured from the onset of the current bad run to the latest
     * snapshot. Returns 0 when the latest matching snapshot is not bad — i.e. the
     * contradiction is not currently present.
     *
     * This is the dwell the architecture doc asks for ("alarm on how long a
     * contradiction persists"), replacing a naive `max−min` span that counts every
     * observation regardless of state. When the whole series is bad the result is a
     * lower bound: we only know the state held from the first snapshot we saw.
     *
     * @param callable(Signal): bool $isBad
     */
    public function dwellSeconds(Signal $reference, callable $isBad): int
    {
        $run = $this->currentBadRun($reference, $isBad);

        if ($run === null) {
            return 0;
        }

        return $run['latest']->timestamp->getTimestamp() - $run['onset']->timestamp->getTimestamp();
    }

    /**
     * When the current state is bad (per `$isBad`), the estimated onset of that bad
     * run — the same walk that backs dwellSeconds(), exposed as a point in time so
     * attribution can compare a symptom's onset against its cause's. Null when the
     * latest matching snapshot is not bad (no current bad run) or nothing matches.
     * The estimate is censored when the run reaches the series' first snapshot
     * (never observed healthy — see OnsetEstimate).
     *
     * @param callable(Signal): bool $isBad
     */
    public function onsetFor(Signal $reference, callable $isBad): ?OnsetEstimate
    {
        $run = $this->currentBadRun($reference, $isBad);

        if ($run === null) {
            return null;
        }

        return new OnsetEstimate($run['onset']->timestamp, $run['censored']);
    }

    /**
     * The unbroken run of bad snapshots ending at the latest snapshot of the series
     * matching `$reference` — the shared walk behind dwell and onset. Null when the
     * latest matching snapshot is not bad or nothing matches. `censored` is true
     * when the run includes the first snapshot (the true onset may be earlier).
     *
     * @param callable(Signal): bool $isBad
     * @return ?array{onset: Signal, latest: Signal, censored: bool}
     */
    private function currentBadRun(Signal $reference, callable $isBad): ?array
    {
        $matching = $this->matchingSeries($reference);

        if ($matching === []) {
            return null;
        }

        usort($matching, static fn (Signal $a, Signal $b): int => $a->timestamp <=> $b->timestamp);

        $latest = $matching[count($matching) - 1];

        if (! $isBad($latest)) {
            return null;
        }

        // Walk back over the contiguous run of bad snapshots ending at the latest;
        // the onset is the earliest snapshot in that unbroken run.
        $onset = $latest;

        for ($i = count($matching) - 2; $i >= 0; $i--) {
            if (! $isBad($matching[$i])) {
                break;
            }

            $onset = $matching[$i];
        }

        return ['onset' => $onset, 'latest' => $latest, 'censored' => $onset === $matching[0]];
    }

    /**
     * The learned-normal baseline for `$metric` across the series matching
     * `$reference`'s `(type, entity)`. Non-numeric or absent readings are skipped;
     * an empty/short series yields a baseline the caller can detect via
     * sampleCount() and fall back from (cold start).
     */
    public function baselineFor(Signal $reference, string $metric): MetricBaseline
    {
        $samples = [];

        foreach ($this->matchingSeries($reference) as $signal) {
            $value = $signal->attributes[$metric] ?? null;

            if (is_int($value) || is_float($value)) {
                $samples[] = $value;
            }
        }

        return new MetricBaseline($samples);
    }

    /**
     * The signals whose `(type, entity-set)` matches `$reference` — i.e. the series
     * of one logical entity across polls.
     *
     * @return list<Signal>
     */
    private function matchingSeries(Signal $reference): array
    {
        $key = $this->key($reference);
        $matching = [];

        foreach ($this->signals as $signal) {
            if ($this->key($signal) === $key) {
                $matching[] = $signal;
            }
        }

        return $matching;
    }

    /**
     * A stable key for a signal's `(type, entity-set)` so snapshots of the same
     * logical entity across polls share it. Entity references are sorted so order
     * does not affect the key.
     */
    private function key(Signal $signal): string
    {
        $entityKeys = array_map(
            static fn (EntityReference $entity): string => $entity->type."\x1f".$entity->id,
            $signal->entityReferences,
        );

        sort($entityKeys);

        return $signal->type->value."\0".implode("\x1e", $entityKeys);
    }
}
