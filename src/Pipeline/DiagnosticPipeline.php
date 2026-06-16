<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Pipeline;

use Mquevedob\Provado\Config\ProvadoConfig;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Correlation\CorrelationEngine;
use Mquevedob\Provado\Incidents\IncidentReportBuilder;
use Mquevedob\Provado\Patterns\DiagnosticPatternRegistry;
use Mquevedob\Provado\Sources\SourceAdapterRegistry;
use Mquevedob\Provado\Storage\SignalStore;

/**
 * Runs the full Alpha loop end-to-end over already-configured sources:
 * fetch/normalize signals, store them, correlate, evaluate diagnostic
 * patterns, and build a single aggregated incident report.
 */
final readonly class DiagnosticPipeline
{
    public function __construct(
        private SourceAdapterRegistry $adapters,
        private SignalStore $store,
        private DiagnosticPatternRegistry $patterns,
        private IncidentReportBuilder $reportBuilder,
    ) {
    }

    public function run(ProvadoConfig $config, TimeWindow $window): PipelineResult
    {
        $signals = [];
        $errors = [];

        foreach ($this->adapters->enabledAdaptersFor($config) as $sourceName => $adapter) {
            $result = $adapter->fetch($config->source($sourceName), $window);

            foreach ($result->signals() as $signal) {
                $signals[] = $signal;
            }

            foreach ($result->errors() as $error) {
                $errors[] = $error;
            }
        }

        $this->store->saveMany($signals);

        $groups = (new CorrelationEngine($this->store))->correlate($window);

        $findings = [];

        foreach ($groups as $group) {
            foreach ($this->patterns->evaluate($group) as $evaluation) {
                foreach ($evaluation->findings() as $finding) {
                    $findings[] = $finding;
                }
            }
        }

        return new PipelineResult(
            report: $this->reportBuilder->build($findings),
            signalCount: count($signals),
            correlationGroupCount: count($groups),
            errors: $errors,
        );
    }
}
