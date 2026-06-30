<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Diagnosis;

/**
 * An explicit dependency graph: one upstream cause and its downstream edges. The
 * architecture doc's v1 lead pattern is "cron death → cache/index/email staleness
 * collapse" — New Relic does not know Magento's dependency graph, so this encodes
 * it. A pattern uses the graph to collapse co-occurring downstream symptoms into
 * the one upstream verdict instead of emitting N independent alerts.
 *
 * The graph carries every declared edge, lit or dark. Lighting a dark edge up is a
 * matter of supplying its feeding signal (a later version's job), not rebuilding
 * the graph — the symptom semantics for each edge live with the pattern that owns
 * the cause.
 */
final readonly class DependencyGraph
{
    /**
     * @param list<DependencyEdge> $edges
     */
    public function __construct(public string $upstream, public array $edges)
    {
    }

    /**
     * The lit edge that a signal of this type feeds, if any — so a pattern can map
     * a downstream signal to its edge without a per-type conditional.
     */
    public function edgeForSignalType(string $signalType): ?DependencyEdge
    {
        foreach ($this->edges as $edge) {
            if ($edge->active && $edge->signalType === $signalType) {
                return $edge;
            }
        }

        return null;
    }

    /**
     * Declared-but-dark edge nodes — dependencies the graph knows about but has no
     * feeding signal for yet. Surfaced as evidence so it is visible that the graph
     * is complete and which edges await their signal.
     *
     * @return list<string>
     */
    public function darkEdges(): array
    {
        $dark = [];

        foreach ($this->edges as $edge) {
            if (! $edge->active) {
                $dark[] = $edge->node;
            }
        }

        return $dark;
    }

    /**
     * @return list<string>
     */
    public function litEdges(): array
    {
        $lit = [];

        foreach ($this->edges as $edge) {
            if ($edge->active) {
                $lit[] = $edge->node;
            }
        }

        return $lit;
    }
}
