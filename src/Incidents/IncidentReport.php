<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Incidents;

use DateTimeImmutable;
use InvalidArgumentException;
use Mquevedob\Provado\Patterns\DiagnosticFinding;
use Mquevedob\Provado\Support\ContextRedactor;

final readonly class IncidentReport
{
    /**
     * @var list<DiagnosticFinding>
     */
    public array $findings;

    /**
     * @var array<mixed>
     */
    public array $evidence;

    /**
     * @var list<string>
     */
    public array $recommendedNextChecks;

    /**
     * @param array<mixed> $findings
     * @param array<mixed> $evidence
     * @param array<mixed> $recommendedNextChecks
     */
    public function __construct(
        public IncidentReportId $id,
        public string $title,
        public string $summary,
        public IncidentReportSeverity $severity,
        array $findings,
        array $evidence,
        array $recommendedNextChecks,
        public DateTimeImmutable $createdAt,
    ) {
        if (trim($title) === '') {
            throw new InvalidArgumentException('IncidentReport title cannot be empty.');
        }

        if (trim($summary) === '') {
            throw new InvalidArgumentException('IncidentReport summary cannot be empty.');
        }

        $this->findings = $this->validateFindings($findings);
        $this->evidence = (new ContextRedactor())->redact($evidence);
        $this->recommendedNextChecks = $this->validateRecommendedNextChecks($recommendedNextChecks);
    }

    /**
     * @param array<mixed> $findings
     * @return list<DiagnosticFinding>
     */
    private function validateFindings(array $findings): array
    {
        $validated = [];

        foreach ($findings as $finding) {
            if (! $finding instanceof DiagnosticFinding) {
                throw new InvalidArgumentException('IncidentReport findings must be DiagnosticFinding instances.');
            }

            $validated[] = $finding;
        }

        return $validated;
    }

    /**
     * @param array<mixed> $recommendedNextChecks
     * @return list<string>
     */
    private function validateRecommendedNextChecks(array $recommendedNextChecks): array
    {
        $validated = [];

        foreach ($recommendedNextChecks as $recommendedNextCheck) {
            if (! is_string($recommendedNextCheck)) {
                throw new InvalidArgumentException('IncidentReport recommended next checks must be strings.');
            }

            $validated[] = $recommendedNextCheck;
        }

        return $validated;
    }
}
