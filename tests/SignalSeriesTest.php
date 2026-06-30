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
use Mquevedob\Provado\Diagnosis\SignalSeries;
use PHPUnit\Framework\TestCase;

class SignalSeriesTest extends TestCase
{
    public function test_latest_per_entity_collapses_repeated_snapshots_to_the_newest(): void
    {
        $series = new SignalSeries([
            $this->indexer('catalogsearch_fulltext', 10, '11:00'),
            $this->indexer('catalogsearch_fulltext', 0, '11:10'),
            $this->indexer('catalogsearch_fulltext', 5, '11:05'),
        ]);

        $latest = $series->latestPerEntity();

        $this->assertCount(1, $latest);
        $this->assertSame(0, $latest[0]->attributes['backlog']);
        $this->assertSame('11:10', $latest[0]->timestamp->format('H:i'));
    }

    public function test_latest_per_entity_keeps_distinct_entities_separate(): void
    {
        $series = new SignalSeries([
            $this->indexer('catalogsearch_fulltext', 10, '11:00'),
            $this->indexer('catalog_product_price', 3, '11:00'),
            $this->indexer('catalogsearch_fulltext', 0, '11:10'),
        ]);

        $latest = $series->latestPerEntity();

        $this->assertCount(2, $latest);
        $views = array_map(static fn (Signal $s): string => $s->entityReferences[0]->id, $latest);
        $this->assertContains('catalogsearch_fulltext', $views);
        $this->assertContains('catalog_product_price', $views);
    }

    public function test_latest_per_entity_distinguishes_by_signal_type(): void
    {
        // Same host entity, different signal types must not collapse together.
        $series = new SignalSeries([
            $this->typed('cron_health', '11:00'),
            $this->typed('config_change', '11:00'),
        ]);

        $this->assertCount(2, $series->latestPerEntity());
    }

    public function test_latest_per_entity_is_empty_for_an_empty_series(): void
    {
        $this->assertSame([], (new SignalSeries([]))->latestPerEntity());
    }

    public function test_dwell_is_zero_when_the_latest_snapshot_is_not_bad(): void
    {
        $series = new SignalSeries([
            $this->cronAt(500, '11:00'), // bad earlier
            $this->cronAt(10, '11:10'),  // healthy now
        ]);

        $this->assertSame(0, $series->dwellSeconds($this->cronAt(10, '11:10'), $this->backlogged()));
    }

    public function test_dwell_spans_the_contiguous_bad_run_ending_at_latest(): void
    {
        // Bad at 11:00, 11:05, 11:10 → dwell is 10 minutes (600s).
        $series = new SignalSeries([
            $this->cronAt(300, '11:00'),
            $this->cronAt(400, '11:05'),
            $this->cronAt(500, '11:10'),
        ]);

        $this->assertSame(600, $series->dwellSeconds($this->cronAt(500, '11:10'), $this->backlogged()));
    }

    public function test_dwell_starts_at_the_onset_after_a_healthy_snapshot(): void
    {
        // Healthy at 11:00, then bad from 11:05 → onset is 11:05, dwell 5 min (300s)
        // even though an earlier bad snapshot exists before the healthy one.
        $series = new SignalSeries([
            $this->cronAt(500, '10:50'), // bad, but before the healthy break
            $this->cronAt(10, '11:00'),  // healthy break resets the run
            $this->cronAt(300, '11:05'), // onset of the current bad run
            $this->cronAt(400, '11:10'),
        ]);

        $this->assertSame(300, $series->dwellSeconds($this->cronAt(400, '11:10'), $this->backlogged()));
    }

    public function test_dwell_of_a_single_bad_snapshot_is_zero(): void
    {
        $series = new SignalSeries([$this->cronAt(500, '11:00')]);

        $this->assertSame(0, $series->dwellSeconds($this->cronAt(500, '11:00'), $this->backlogged()));
    }

    public function test_dwell_is_zero_when_no_snapshot_matches_the_reference(): void
    {
        $series = new SignalSeries([$this->cronAt(500, '11:00')]);

        // Reference is an indexer entity — no cron series matches its key.
        $this->assertSame(0, $series->dwellSeconds($this->indexer('catalogsearch_fulltext', 9, '11:00'), $this->backlogged()));
    }

    public function test_baseline_for_collects_the_metric_across_the_matching_series(): void
    {
        $series = new SignalSeries([
            $this->cronAt(300, '11:00'),
            $this->cronAt(500, '11:05'),
            $this->cronAt(400, '11:10'),
        ]);

        $baseline = $series->baselineFor($this->cronAt(0, '11:15'), 'pending');

        $this->assertSame(3, $baseline->sampleCount());
        $this->assertSame(400.0, $baseline->median());
    }

    public function test_baseline_for_is_empty_when_no_snapshot_matches_the_reference(): void
    {
        $series = new SignalSeries([$this->cronAt(300, '11:00')]);

        $baseline = $series->baselineFor($this->indexer('catalogsearch_fulltext', 1, '11:00'), 'pending');

        $this->assertSame(0, $baseline->sampleCount());
    }

    /**
     * Predicate: a cron snapshot is "bad" when its pending backlog exceeds 100.
     *
     * @return callable(Signal): bool
     */
    private function backlogged(): callable
    {
        return static fn (Signal $s): bool => (int) ($s->attributes['pending'] ?? 0) > 100;
    }

    private function cronAt(int $pending, string $hhmm): Signal
    {
        return $this->signal('cron_health', [new EntityReference('host', 'provado')], ['pending' => $pending], $hhmm);
    }

    private function indexer(string $view, int $backlog, string $hhmm): Signal
    {
        return $this->signal('indexer_status', [
            new EntityReference('indexer', $view),
            new EntityReference('host', 'provado'),
        ], ['backlog' => $backlog, 'invalid' => 0, 'working' => 0], $hhmm);
    }

    private function typed(string $type, string $hhmm): Signal
    {
        return $this->signal($type, [new EntityReference('host', 'provado')], ['value' => 1], $hhmm);
    }

    /**
     * @param list<EntityReference> $entities
     * @param array<string, int> $attributes
     */
    private function signal(string $type, array $entities, array $attributes, string $hhmm): Signal
    {
        static $seq = 0;
        ++$seq;

        return new Signal(
            id: new SignalId('magento:'.$type.':'.$seq),
            source: new SignalSource('magento'),
            type: new SignalType($type),
            timestamp: new DateTimeImmutable('2026-06-30T'.$hhmm.':00+00:00'),
            severity: SignalSeverity::info(),
            entityReferences: $entities,
            attributes: $attributes,
            rawPayloadReference: new RawPayloadReference($type.':'.$seq),
        );
    }
}
