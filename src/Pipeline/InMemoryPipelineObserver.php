<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Pipeline;

use Mquevedob\Provado\Core\TimeWindow;

/**
 * Records pipeline events in memory for inspection and testing.
 */
final class InMemoryPipelineObserver implements PipelineObserver
{
    /**
     * @var list<array{event: string, data: array<string, mixed>}>
     */
    private array $events = [];

    public function runStarted(TimeWindow $window): void
    {
        $this->record('run_started', []);
    }

    public function sourceFetched(SourceFetchSummary $summary): void
    {
        $this->record('source_fetched', [
            'source' => $summary->sourceName,
            'signals' => $summary->signalCount,
            'errors' => $summary->errorCount,
            'retryable' => $summary->retryableErrorCount,
        ]);
    }

    public function sourceRetried(string $sourceName, int $attempt): void
    {
        $this->record('source_retried', ['source' => $sourceName, 'attempt' => $attempt]);
    }

    public function correlationCompleted(int $groupCount): void
    {
        $this->record('correlation_completed', ['groups' => $groupCount]);
    }

    public function patternEvaluated(string $patternId, int $findingCount): void
    {
        $this->record('pattern_evaluated', ['pattern' => $patternId, 'findings' => $findingCount]);
    }

    public function stageFailed(PipelineError $error): void
    {
        $this->record('stage_failed', ['stage' => $error->stage, 'code' => $error->code]);
    }

    public function runCompleted(PipelineDiagnostics $diagnostics): void
    {
        $this->record('run_completed', [
            'signals' => $diagnostics->signalCount,
            'groups' => $diagnostics->correlationGroupCount,
            'findings' => $diagnostics->findingCount,
            'report' => $diagnostics->reportProduced,
        ]);
    }

    /**
     * @return list<array{event: string, data: array<string, mixed>}>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * @return list<string>
     */
    public function eventNames(): array
    {
        return array_map(static fn (array $event): string => $event['event'], $this->events);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function record(string $event, array $data): void
    {
        $this->events[] = ['event' => $event, 'data' => $data];
    }
}
