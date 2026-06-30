<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests;

use Mquevedob\Provado\Diagnosis\DependencyEdge;
use Mquevedob\Provado\Diagnosis\DependencyGraph;
use PHPUnit\Framework\TestCase;

class DependencyGraphTest extends TestCase
{
    public function test_lit_and_dark_factories_set_the_edge_shape(): void
    {
        $lit = DependencyEdge::lit('index', 'indexer', 'indexer_status');
        $this->assertTrue($lit->active);
        $this->assertSame('indexer_status', $lit->signalType);

        $dark = DependencyEdge::dark('email', 'consumer');
        $this->assertFalse($dark->active);
        $this->assertNull($dark->signalType);
    }

    public function test_graph_separates_lit_from_dark_edges(): void
    {
        $graph = $this->cronGraph();

        $this->assertSame('cron', $graph->upstream);
        $this->assertSame(['index', 'queue', 'cache'], $graph->litEdges());
        $this->assertSame(['email'], $graph->darkEdges());
    }

    public function test_edge_for_signal_type_resolves_only_lit_edges(): void
    {
        $graph = $this->cronGraph();

        $this->assertSame('index', $graph->edgeForSignalType('indexer_status')?->node);
        $this->assertSame('queue', $graph->edgeForSignalType('queue_backlog')?->node);
        $this->assertSame('cache', $graph->edgeForSignalType('cache_validity')?->node);
        // A dark edge's (future) signal and a non-downstream type resolve to nothing.
        $this->assertNull($graph->edgeForSignalType('consumer_liveness'));
        $this->assertNull($graph->edgeForSignalType('config_change'));
    }

    private function cronGraph(): DependencyGraph
    {
        return new DependencyGraph('cron', [
            DependencyEdge::lit('index', 'indexer', 'indexer_status'),
            DependencyEdge::lit('queue', 'queue', 'queue_backlog'),
            DependencyEdge::lit('cache', 'cache', 'cache_validity'),
            DependencyEdge::dark('email', 'consumer'),
        ]);
    }
}
