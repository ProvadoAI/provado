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
