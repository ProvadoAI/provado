<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Pipeline;

use Mquevedob\Provado\Core\TimeWindow;
use Psr\Log\LoggerInterface;

/**
 * Bridges pipeline events to any PSR-3 logger. Only secret-safe summary data
 * is logged; signal payloads are never included.
 */
final readonly class PsrLoggerObserver implements PipelineObserver
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function runStarted(TimeWindow $window): void
    {
        $this->logger->info('Pipeline run started.', [
            'window_start' => $window->start->format(DATE_ATOM),
            'window_end' => $window->end->format(DATE_ATOM),
        ]);
    }

    public function sourceFetched(SourceFetchSummary $summary): void
    {
        $this->logger->info('Pipeline source fetched.', [
            'source' => $summary->sourceName,
            'signals' => $summary->signalCount,
            'errors' => $summary->errorCount,
            'retryable_errors' => $summary->retryableErrorCount,
        ]);
    }

    public function sourceRetried(string $sourceName, int $attempt): void
    {
        $this->logger->warning('Pipeline source retried.', [
            'source' => $sourceName,
            'attempt' => $attempt,
        ]);
    }

    public function correlationCompleted(int $groupCount): void
    {
        $this->logger->info('Pipeline correlation completed.', ['groups' => $groupCount]);
    }

    public function patternEvaluated(string $patternId, int $findingCount): void
    {
        $this->logger->info('Pipeline pattern evaluated.', [
            'pattern' => $patternId,
            'findings' => $findingCount,
        ]);
    }

    public function stageFailed(PipelineError $error): void
    {
        $this->logger->warning('Pipeline stage failed.', [
            'stage' => $error->stage,
            'code' => $error->code,
            'message' => $error->message,
            'context' => $error->context,
        ]);
    }

    public function runCompleted(PipelineDiagnostics $diagnostics): void
    {
        $this->logger->info('Pipeline run completed.', [
            'signals' => $diagnostics->signalCount,
            'groups' => $diagnostics->correlationGroupCount,
            'patterns_matched' => $diagnostics->patternsMatched,
            'patterns_evaluated' => $diagnostics->patternsEvaluated,
            'findings' => $diagnostics->findingCount,
            'report_produced' => $diagnostics->reportProduced,
        ]);
    }
}
