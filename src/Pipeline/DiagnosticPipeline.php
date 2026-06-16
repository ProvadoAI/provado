<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Pipeline;

use Mquevedob\Provado\Config\ProvadoConfig;
use Mquevedob\Provado\Config\SourceConfig;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Correlation\CorrelationEngine;
use Mquevedob\Provado\Incidents\IncidentReportBuilder;
use Mquevedob\Provado\Patterns\DiagnosticPatternRegistry;
use Mquevedob\Provado\Sources\SourceAdapter;
use Mquevedob\Provado\Sources\SourceAdapterRegistry;
use Mquevedob\Provado\Sources\SourceFetchError;
use Mquevedob\Provado\Sources\SourceFetchResult;
use Mquevedob\Provado\Storage\SignalStoreFactory;
use Throwable;

/**
 * Runs the full Alpha loop end-to-end over already-configured sources:
 * fetch/normalize signals, store them, correlate, evaluate diagnostic
 * patterns, and build a single aggregated incident report.
 *
 * Each run works in a fresh store so runs are self-contained, and a failure
 * in any stage is captured rather than aborting the whole run.
 */
final readonly class DiagnosticPipeline
{
    public function __construct(
        private SourceAdapterRegistry $adapters,
        private SignalStoreFactory $storeFactory,
        private DiagnosticPatternRegistry $patterns,
        private IncidentReportBuilder $reportBuilder,
        private PipelineObserver $observer = new NullPipelineObserver(),
        private RetryPolicy $retryPolicy = new NoRetryPolicy(),
    ) {
    }

    public function run(ProvadoConfig $config, TimeWindow $window): PipelineResult
    {
        $this->observer->runStarted($window);

        $store = $this->storeFactory->create();

        $signals = [];
        $errors = [];
        $stageErrors = [];
        $sources = [];
        $durations = [];

        $fetchStart = hrtime(true);

        foreach ($this->adapters->enabledAdaptersFor($config) as $sourceName => $adapter) {
            $result = $this->fetchSource($adapter, $config->source($sourceName), $window);

            $sourceSignals = $result->signals();
            $sourceErrors = $result->errors();
            $retryableErrors = 0;

            foreach ($sourceSignals as $signal) {
                $signals[] = $signal;
            }

            foreach ($sourceErrors as $error) {
                $errors[] = $error;

                if ($error->retryable === true) {
                    $retryableErrors++;
                }
            }

            $summary = new SourceFetchSummary(
                sourceName: $sourceName,
                signalCount: count($sourceSignals),
                errorCount: count($sourceErrors),
                retryableErrorCount: $retryableErrors,
            );

            $sources[] = $summary;
            $this->observer->sourceFetched($summary);
        }

        $store->saveMany($signals);
        $durations['fetch'] = $this->elapsedMs($fetchStart);

        $correlationStart = hrtime(true);
        $groups = [];

        try {
            $groups = (new CorrelationEngine($store))->correlate($window);
            $this->observer->correlationCompleted(count($groups));
        } catch (Throwable $exception) {
            $error = new PipelineError(
                stage: 'correlation',
                message: 'Correlation failed.',
                code: 'correlation_failed',
                context: ['reason' => $exception->getMessage()],
            );
            $stageErrors[] = $error;
            $this->observer->stageFailed($error);
        }

        $durations['correlation'] = $this->elapsedMs($correlationStart);

        $evaluationStart = hrtime(true);
        $findings = [];
        $patternsMatched = 0;
        $patternsEvaluated = 0;

        foreach ($groups as $group) {
            foreach ($this->patterns->all() as $patternId => $pattern) {
                try {
                    if (! $pattern->supports($group)) {
                        continue;
                    }

                    $patternsMatched++;
                    $evaluation = $pattern->evaluate($group);
                    $patternsEvaluated++;

                    $patternFindings = $evaluation->findings();

                    foreach ($patternFindings as $finding) {
                        $findings[] = $finding;
                    }

                    $this->observer->patternEvaluated($patternId, count($patternFindings));
                } catch (Throwable $exception) {
                    $error = new PipelineError(
                        stage: 'pattern_evaluation',
                        message: 'Diagnostic pattern evaluation failed.',
                        code: 'pattern_failed',
                        context: [
                            'pattern' => $patternId,
                            'reason' => $exception->getMessage(),
                        ],
                    );
                    $stageErrors[] = $error;
                    $this->observer->stageFailed($error);
                }
            }
        }

        $durations['pattern_evaluation'] = $this->elapsedMs($evaluationStart);

        $reportStart = hrtime(true);
        $report = null;

        try {
            $report = $this->reportBuilder->build($findings);
        } catch (Throwable $exception) {
            $error = new PipelineError(
                stage: 'report',
                message: 'Incident report building failed.',
                code: 'report_failed',
                context: ['reason' => $exception->getMessage()],
            );
            $stageErrors[] = $error;
            $this->observer->stageFailed($error);
        }

        $durations['report'] = $this->elapsedMs($reportStart);

        $diagnostics = new PipelineDiagnostics(
            sources: $sources,
            signalCount: count($signals),
            correlationGroupCount: count($groups),
            patternsMatched: $patternsMatched,
            patternsEvaluated: $patternsEvaluated,
            findingCount: count($findings),
            reportProduced: $report !== null,
            stageDurationsMs: $durations,
        );

        $this->observer->runCompleted($diagnostics);

        return new PipelineResult(
            report: $report,
            diagnostics: $diagnostics,
            errors: $errors,
            stageErrors: $stageErrors,
        );
    }

    /**
     * Fetches a source, retrying while it returns retryable errors and the
     * retry policy still permits attempts. A thrown adapter error is converted
     * into a non-retryable fetch error so one source cannot abort the run.
     */
    private function fetchSource(SourceAdapter $adapter, SourceConfig $config, TimeWindow $window): SourceFetchResult
    {
        $maxAttempts = $this->retryPolicy->maxAttempts();
        $attempt = 1;

        try {
            $result = $adapter->fetch($config, $window);

            while ($attempt < $maxAttempts && $this->hasRetryableError($result)) {
                $attempt++;
                $this->observer->sourceRetried($config->name, $attempt);
                $result = $adapter->fetch($config, $window);
            }

            return $result;
        } catch (Throwable $exception) {
            return SourceFetchResult::empty()->withErrors([
                new SourceFetchError(
                    sourceName: $config->name,
                    message: 'Source fetch failed.',
                    code: 'fetch_failed',
                    retryable: false,
                    context: ['reason' => $exception->getMessage()],
                ),
            ]);
        }
    }

    private function hasRetryableError(SourceFetchResult $result): bool
    {
        foreach ($result->errors() as $error) {
            if ($error->retryable === true) {
                return true;
            }
        }

        return false;
    }

    private function elapsedMs(int $startNanoseconds): float
    {
        return (hrtime(true) - $startNanoseconds) / 1_000_000;
    }
}
