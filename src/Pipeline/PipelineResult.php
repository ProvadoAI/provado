<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Pipeline;

use Mquevedob\Provado\Incidents\IncidentReport;
use Mquevedob\Provado\Sources\SourceFetchError;

final readonly class PipelineResult
{
    /**
     * @param list<SourceFetchError> $errors
     * @param list<PipelineError> $stageErrors
     */
    public function __construct(
        public ?IncidentReport $report,
        public PipelineDiagnostics $diagnostics,
        public array $errors,
        public array $stageErrors,
    ) {
    }

    public function hasReport(): bool
    {
        return $this->report !== null;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [] || $this->stageErrors !== [];
    }
}
