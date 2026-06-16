<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Pipeline;

use Mquevedob\Provado\Config\ProvadoConfig;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Correlation\CorrelationEngine;
use Mquevedob\Provado\Incidents\IncidentReportBuilder;
use Mquevedob\Provado\Patterns\DiagnosticPatternRegistry;
use Mquevedob\Provado\Sources\SourceAdapterRegistry;
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
    ) {
    }

    public function run(ProvadoConfig $config, TimeWindow $window): PipelineResult
    {
        $store = $this->storeFactory->create();

        $signals = [];
        $errors = [];
        $stageErrors = [];
        $sources = [];
        $durations = [];

        $fetchStart = hrtime(true);

        foreach ($this->adapters->enabledAdaptersFor($config) as $sourceName => $adapter) {
            $result = $adapter->fetch($config->source($sourceName), $window);

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

            $sources[] = new SourceFetchSummary(
                sourceName: $sourceName,
                signalCount: count($sourceSignals),
                errorCount: count($sourceErrors),
                retryableErrorCount: $retryableErrors,
            );
        }

        $store->saveMany($signals);
        $durations['fetch'] = $this->elapsedMs($fetchStart);

        $correlationStart = hrtime(true);
        $groups = [];

        try {
            $groups = (new CorrelationEngine($store))->correlate($window);
        } catch (Throwable $exception) {
            $stageErrors[] = new PipelineError(
                stage: 'correlation',
                message: 'Correlation failed.',
                code: 'correlation_failed',
                context: ['reason' => $exception->getMessage()],
            );
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

                    foreach ($evaluation->findings() as $finding) {
                        $findings[] = $finding;
                    }
                } catch (Throwable $exception) {
                    $stageErrors[] = new PipelineError(
                        stage: 'pattern_evaluation',
                        message: 'Diagnostic pattern evaluation failed.',
                        code: 'pattern_failed',
                        context: [
                            'pattern' => $patternId,
                            'reason' => $exception->getMessage(),
                        ],
                    );
                }
            }
        }

        $durations['pattern_evaluation'] = $this->elapsedMs($evaluationStart);

        $reportStart = hrtime(true);
        $report = null;

        try {
            $report = $this->reportBuilder->build($findings);
        } catch (Throwable $exception) {
            $stageErrors[] = new PipelineError(
                stage: 'report',
                message: 'Incident report building failed.',
                code: 'report_failed',
                context: ['reason' => $exception->getMessage()],
            );
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

        return new PipelineResult(
            report: $report,
            diagnostics: $diagnostics,
            errors: $errors,
            stageErrors: $stageErrors,
        );
    }

    private function elapsedMs(int $startNanoseconds): float
    {
        return (hrtime(true) - $startNanoseconds) / 1_000_000;
    }
}
