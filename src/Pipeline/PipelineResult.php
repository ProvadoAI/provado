<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Pipeline;

use Mquevedob\Provado\Incidents\IncidentReport;
use Mquevedob\Provado\Sources\SourceFetchError;

final readonly class PipelineResult
{
    /**
     * @param list<SourceFetchError> $errors
     */
    public function __construct(
        public ?IncidentReport $report,
        public int $signalCount,
        public int $correlationGroupCount,
        public array $errors,
    ) {
    }

    public function hasReport(): bool
    {
        return $this->report !== null;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
