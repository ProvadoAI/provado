<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Pipeline;

/**
 * Structured, secret-safe summary of a single pipeline run, suitable for
 * logging or surfacing in a demo without exposing signal payloads.
 */
final readonly class PipelineDiagnostics
{
    /**
     * @param list<SourceFetchSummary> $sources
     * @param array<string, float> $stageDurationsMs
     */
    public function __construct(
        public array $sources,
        public int $signalCount,
        public int $correlationGroupCount,
        public int $patternsMatched,
        public int $patternsEvaluated,
        public int $findingCount,
        public bool $reportProduced,
        public array $stageDurationsMs,
    ) {
    }
}
