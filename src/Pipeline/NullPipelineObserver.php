<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Pipeline;

use Mquevedob\Provado\Core\TimeWindow;

/**
 * Default observer that ignores every event. Used when no observability is wired.
 */
final readonly class NullPipelineObserver implements PipelineObserver
{
    public function runStarted(TimeWindow $window): void
    {
    }

    public function sourceFetched(SourceFetchSummary $summary): void
    {
    }

    public function sourceRetried(string $sourceName, int $attempt): void
    {
    }

    public function correlationCompleted(int $groupCount): void
    {
    }

    public function patternEvaluated(string $patternId, int $findingCount): void
    {
    }

    public function stageFailed(PipelineError $error): void
    {
    }

    public function runCompleted(PipelineDiagnostics $diagnostics): void
    {
    }
}
