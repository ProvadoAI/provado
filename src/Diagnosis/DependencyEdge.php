<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Diagnosis;

/**
 * One downstream edge of a dependency graph: an upstream cause (e.g. cron) is
 * upstream of this node (cache / index / email / queue), so when the cause is
 * degraded a symptom on this node collapses under it instead of alarming alone.
 *
 * An edge is **lit** when a `ProvadoSignal` type feeds it (`signalType` set,
 * `active` true) and **dark** when the graph declares the dependency but no signal
 * ships for it yet (`signalType` null, `active` false). Dark edges are intentional:
 * the graph is complete, and a later version lights an edge up by supplying its
 * signal — not by redesigning the graph.
 */
final readonly class DependencyEdge
{
    public function __construct(
        public string $node,
        public string $entityType,
        public ?string $signalType,
        public bool $active,
    ) {
    }

    public static function lit(string $node, string $entityType, string $signalType): self
    {
        return new self($node, $entityType, $signalType, true);
    }

    public static function dark(string $node, string $entityType): self
    {
        return new self($node, $entityType, null, false);
    }
}
