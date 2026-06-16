<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Pipeline;

use Mquevedob\Provado\Core\TimeWindow;

/**
 * Receives structured, secret-safe events at pipeline stage boundaries.
 * Implementations must not assume any event is emitted (a run may fail early).
 */
interface PipelineObserver
{
    public function runStarted(TimeWindow $window): void;

    public function sourceFetched(SourceFetchSummary $summary): void;

    public function sourceRetried(string $sourceName, int $attempt): void;

    public function correlationCompleted(int $groupCount): void;

    public function patternEvaluated(string $patternId, int $findingCount): void;

    public function stageFailed(PipelineError $error): void;

    public function runCompleted(PipelineDiagnostics $diagnostics): void;
}
