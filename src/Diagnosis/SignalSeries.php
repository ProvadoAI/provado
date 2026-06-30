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
        $key = $this->key($reference);

        $matching = [];

        foreach ($this->signals as $signal) {
            if ($this->key($signal) === $key) {
                $matching[] = $signal;
            }
        }

        if ($matching === []) {
            return 0;
        }

        usort($matching, static fn (Signal $a, Signal $b): int => $a->timestamp <=> $b->timestamp);

        $latest = $matching[count($matching) - 1];

        if (! $isBad($latest)) {
            return 0;
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

        return $latest->timestamp->getTimestamp() - $onset->timestamp->getTimestamp();
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
