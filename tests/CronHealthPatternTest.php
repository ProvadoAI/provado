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
use Mquevedob\Provado\Patterns\Operations\CronHealthPattern;
use PHPUnit\Framework\TestCase;

class CronHealthPatternTest extends TestCase
{
    public function test_supports_requires_a_cron_health_signal(): void
    {
        $pattern = new CronHealthPattern();

        $this->assertTrue($pattern->supports(new CorrelationGroup([$this->cron(['pending' => 1])])));
        $this->assertFalse($pattern->supports(new CorrelationGroup([$this->indexer('catalogsearch_fulltext', 5, 0)])));
    }

    public function test_healthy_cron_produces_no_finding(): void
    {
        $group = new CorrelationGroup([$this->cron(['pending' => 5, 'running' => 0, 'missed' => 0, 'error' => 10])]);

        $this->assertFalse((new CronHealthPattern())->evaluate($group)->hasFindings());
    }

    public function test_elevated_backlog_and_errors_produce_an_error_finding(): void
    {
        $group = new CorrelationGroup([$this->cron(['pending' => 378, 'running' => 0, 'missed' => 0, 'error' => 2855])]);

        $result = (new CronHealthPattern())->evaluate($group);

        $this->assertTrue($result->hasFindings());
        $finding = $result->findings()[0];
        $this->assertSame('cron_health', $finding->patternId);
        $this->assertSame('error', $finding->severity->value);
        $this->assertStringContainsString('378 pending', $finding->summary);
        $this->assertSame(2855, $finding->evidence['cron']['error']);
    }

    public function test_missed_jobs_are_critical(): void
    {
        $group = new CorrelationGroup([$this->cron(['pending' => 0, 'running' => 0, 'missed' => 4, 'error' => 0])]);

        $finding = (new CronHealthPattern())->evaluate($group)->findings()[0];

        $this->assertSame('critical', $finding->severity->value);
    }

    public function test_downstream_symptoms_are_collapsed_into_the_cron_verdict(): void
    {
        $group = new CorrelationGroup([
            $this->cron(['pending' => 378, 'running' => 0, 'missed' => 0, 'error' => 2855]),
            $this->indexer('catalogsearch_fulltext', 120, 0),
            $this->queue('async.operations.all', 50, 0),
            $this->indexer('catalog_product_price', 0, 0), // healthy → not a symptom
        ]);

        $finding = (new CronHealthPattern())->evaluate($group)->findings()[0];

        $this->assertContains('indexer catalogsearch_fulltext', $finding->evidence['downstream_symptoms']);
        $this->assertContains('queue async.operations.all', $finding->evidence['downstream_symptoms']);
        $this->assertNotContains('indexer catalog_product_price', $finding->evidence['downstream_symptoms']);
        $this->assertStringContainsString('attributed to it', $finding->summary);
    }

    public function test_recent_config_change_is_stamped(): void
    {
        $group = new CorrelationGroup([
            $this->cron(['pending' => 378, 'running' => 0, 'missed' => 0, 'error' => 2855]),
            $this->config(1, 120),
        ]);

        $finding = (new CronHealthPattern())->evaluate($group)->findings()[0];

        $this->assertTrue($finding->evidence['recent_config_change']);
        $this->assertSame(120, $finding->evidence['latest_config_change_age_seconds']);
    }

    public function test_stale_snapshot_does_not_flag_an_entity_that_is_healthy_now(): void
    {
        // Continuous shipping: the window holds several snapshots per indexer. An
        // indexer that was in backlog earlier but is healthy in its latest snapshot
        // must not be attributed as a downstream symptom.
        $group = new CorrelationGroup([
            $this->cron(['pending' => 378, 'running' => 0, 'missed' => 0, 'error' => 2855]),
            $this->indexerAt('catalogsearch_fulltext', 120, '14:50'), // stale: was backlogged
            $this->indexerAt('catalogsearch_fulltext', 0, '15:00'),   // latest: healthy now
            $this->indexerAt('catalog_product_price', 90, '15:00'),   // latest: still backlogged
        ]);

        $symptoms = (new CronHealthPattern())->evaluate($group)->findings()[0]->evidence['downstream_symptoms'];

        $this->assertNotContains('indexer catalogsearch_fulltext', $symptoms);
        $this->assertContains('indexer catalog_product_price', $symptoms);
    }

    public function test_cron_dwell_seconds_measures_the_current_bad_run_not_the_window_span(): void
    {
        // A low-normal pending backlog (5) establishes the baseline, then a spike
        // to 600 from 14:55. Dwell is the bad run (14:55→15:00 = 300s), not the
        // full observation span (1200s). The baseline predicate marks only the
        // spiked snapshots as bad.
        $group = new CorrelationGroup([
            $this->cronAt(['pending' => 5, 'running' => 0, 'missed' => 0, 'error' => 10], '14:40'),
            $this->cronAt(['pending' => 5, 'running' => 0, 'missed' => 0, 'error' => 10], '14:45'),
            $this->cronAt(['pending' => 5, 'running' => 0, 'missed' => 0, 'error' => 10], '14:50'),
            $this->cronAt(['pending' => 600, 'running' => 0, 'missed' => 0, 'error' => 10], '14:55'),
            $this->cronAt(['pending' => 600, 'running' => 0, 'missed' => 0, 'error' => 10], '15:00'),
        ]);

        $finding = (new CronHealthPattern())->evaluate($group)->findings()[0];

        $this->assertSame(300, $finding->evidence['cron_dwell_seconds']);
    }

    public function test_a_stably_high_metric_does_not_fire_once_the_baseline_is_learned(): void
    {
        // The lab case: a persistently high errored-row count that is this
        // instance's normal, not an incident. Above the static floor (500) every
        // poll would fire; the learned baseline recognises it as normal.
        $group = new CorrelationGroup([
            $this->cronAt(['pending' => 5, 'running' => 0, 'missed' => 0, 'error' => 2790], '14:45'),
            $this->cronAt(['pending' => 6, 'running' => 0, 'missed' => 0, 'error' => 2800], '14:50'),
            $this->cronAt(['pending' => 5, 'running' => 0, 'missed' => 0, 'error' => 2810], '14:55'),
            $this->cronAt(['pending' => 4, 'running' => 0, 'missed' => 0, 'error' => 2795], '15:00'),
        ]);

        $this->assertFalse((new CronHealthPattern())->evaluate($group)->hasFindings());
    }

    public function test_a_drift_above_a_low_normal_fires_even_below_the_static_floor(): void
    {
        // Pending normally ~5; a drift to 80 stays under the static floor of 100
        // but is ~16× normal — the learned baseline catches it.
        $group = new CorrelationGroup([
            $this->cronAt(['pending' => 5, 'running' => 0, 'missed' => 0, 'error' => 10], '14:45'),
            $this->cronAt(['pending' => 4, 'running' => 0, 'missed' => 0, 'error' => 10], '14:50'),
            $this->cronAt(['pending' => 6, 'running' => 0, 'missed' => 0, 'error' => 10], '14:55'),
            $this->cronAt(['pending' => 80, 'running' => 0, 'missed' => 0, 'error' => 10], '15:00'),
        ]);

        $result = (new CronHealthPattern())->evaluate($group);

        $this->assertTrue($result->hasFindings());
        $this->assertSame('error', $result->findings()[0]->severity->value);
    }

    public function test_a_worker_stuck_running_past_its_runtime_fires(): void
    {
        // running stays at 1 across the whole window (>=15 min) while backlog and
        // errors are normal — a worker stuck mid-job, not a busy one.
        $group = new CorrelationGroup([
            $this->cronAt(['pending' => 5, 'running' => 1, 'missed' => 0, 'error' => 10], '14:40'),
            $this->cronAt(['pending' => 5, 'running' => 1, 'missed' => 0, 'error' => 10], '14:45'),
            $this->cronAt(['pending' => 5, 'running' => 1, 'missed' => 0, 'error' => 10], '14:50'),
            $this->cronAt(['pending' => 5, 'running' => 1, 'missed' => 0, 'error' => 10], '14:55'),
            $this->cronAt(['pending' => 5, 'running' => 1, 'missed' => 0, 'error' => 10], '15:00'),
        ]);

        $finding = (new CronHealthPattern())->evaluate($group)->findings()[0];

        $this->assertSame('error', $finding->severity->value);
        $this->assertTrue($finding->evidence['running_stuck']);
        $this->assertSame(1200, $finding->evidence['running_dwell_seconds']);
        $this->assertStringContainsString('possible stuck worker', $finding->summary);
    }

    public function test_a_briefly_running_worker_does_not_fire(): void
    {
        // running is non-zero only in the latest snapshot — a job that is busy now,
        // not stuck. Dwell is 0, below the stuck threshold.
        $group = new CorrelationGroup([
            $this->cronAt(['pending' => 5, 'running' => 0, 'missed' => 0, 'error' => 10], '14:45'),
            $this->cronAt(['pending' => 5, 'running' => 0, 'missed' => 0, 'error' => 10], '14:50'),
            $this->cronAt(['pending' => 5, 'running' => 0, 'missed' => 0, 'error' => 10], '14:55'),
            $this->cronAt(['pending' => 5, 'running' => 1, 'missed' => 0, 'error' => 10], '15:00'),
        ]);

        $this->assertFalse((new CronHealthPattern())->evaluate($group)->hasFindings());
    }

    public function test_running_below_the_stuck_threshold_does_not_fire(): void
    {
        // running for two polls (~300s) is under the 900s stuck threshold.
        $group = new CorrelationGroup([
            $this->cronAt(['pending' => 5, 'running' => 0, 'missed' => 0, 'error' => 10], '14:45'),
            $this->cronAt(['pending' => 5, 'running' => 0, 'missed' => 0, 'error' => 10], '14:50'),
            $this->cronAt(['pending' => 5, 'running' => 1, 'missed' => 0, 'error' => 10], '14:55'),
            $this->cronAt(['pending' => 5, 'running' => 1, 'missed' => 0, 'error' => 10], '15:00'),
        ]);

        $this->assertFalse((new CronHealthPattern())->evaluate($group)->hasFindings());
    }

    public function test_a_short_series_falls_back_to_the_static_cold_start_floor(): void
    {
        // Two snapshots is below MIN_BASELINE_SAMPLES, so the static floor applies:
        // error 2855 > 500 fires even though the (tiny) series looks self-consistent.
        $group = new CorrelationGroup([
            $this->cronAt(['pending' => 5, 'running' => 0, 'missed' => 0, 'error' => 2850], '14:55'),
            $this->cronAt(['pending' => 5, 'running' => 0, 'missed' => 0, 'error' => 2855], '15:00'),
        ]);

        $this->assertTrue((new CronHealthPattern())->evaluate($group)->hasFindings());
    }

    /**
     * @param array<string, int> $metrics
     */
    private function cronAt(array $metrics, string $hhmm): Signal
    {
        return new Signal(
            id: new SignalId('magento:cron_health:'.$hhmm),
            source: new SignalSource('magento'),
            type: new SignalType('cron_health'),
            timestamp: new DateTimeImmutable('2026-06-30T'.$hhmm.':00+00:00'),
            severity: SignalSeverity::info(),
            entityReferences: [new EntityReference('host', 'provado')],
            attributes: $metrics,
            rawPayloadReference: new RawPayloadReference('cron_health:'.$hhmm),
        );
    }

    private function indexerAt(string $view, int $backlog, string $hhmm): Signal
    {
        return new Signal(
            id: new SignalId('magento:indexer_status:'.$view.':'.$hhmm),
            source: new SignalSource('magento'),
            type: new SignalType('indexer_status'),
            timestamp: new DateTimeImmutable('2026-06-30T'.$hhmm.':00+00:00'),
            severity: SignalSeverity::info(),
            entityReferences: [
                new EntityReference('indexer', $view),
                new EntityReference('host', 'provado'),
            ],
            attributes: ['backlog' => $backlog, 'invalid' => 0, 'working' => 0],
            rawPayloadReference: new RawPayloadReference('indexer_status:'.$view.':'.$hhmm),
        );
    }

    /**
     * @param array<string, int> $metrics
     */
    private function cron(array $metrics): Signal
    {
        return $this->signal('cron_health', [new EntityReference('host', 'provado')], $metrics);
    }

    private function indexer(string $view, int $backlog, int $invalid): Signal
    {
        return $this->signal('indexer_status', [
            new EntityReference('indexer', $view),
            new EntityReference('host', 'provado'),
        ], ['backlog' => $backlog, 'invalid' => $invalid, 'working' => 0]);
    }

    private function queue(string $name, int $ready, int $consumers): Signal
    {
        return $this->signal('queue_backlog', [
            new EntityReference('queue', $name),
            new EntityReference('host', 'provado'),
        ], ['ready' => $ready, 'unacked' => 0, 'consumers' => $consumers]);
    }

    private function config(int $changed1h, int $ageSeconds): Signal
    {
        return $this->signal('config_change', [new EntityReference('host', 'provado')], [
            'changed_1h' => $changed1h,
            'changed_24h' => $changed1h,
            'latest_change_age_seconds' => $ageSeconds,
        ]);
    }

    /**
     * @param list<EntityReference> $entities
     * @param array<string, int> $attributes
     */
    private function signal(string $type, array $entities, array $attributes): Signal
    {
        static $seq = 0;
        ++$seq;

        return new Signal(
            id: new SignalId('magento:'.$type.':'.$seq),
            source: new SignalSource('magento'),
            type: new SignalType($type),
            timestamp: new DateTimeImmutable('2026-06-30T15:00:00+00:00'),
            severity: SignalSeverity::info(),
            entityReferences: $entities,
            attributes: $attributes,
            rawPayloadReference: new RawPayloadReference($type.':'.$seq),
        );
    }
}
