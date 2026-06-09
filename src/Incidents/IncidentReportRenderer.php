<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Incidents;

use JsonException;

final readonly class IncidentReportRenderer
{
    public function renderText(IncidentReport $report): string
    {
        return implode("\n", [
            'Incident Report',
            '===============',
            'ID: '.$report->id->value,
            'Created At: '.$report->createdAt->format(DATE_ATOM),
            'Title: '.$report->title,
            'Severity: '.$report->severity->value,
            'Summary: '.$report->summary,
            'Finding Count: '.count($report->findings),
            '',
            'Evidence:',
            $this->renderEvidence($report->evidence),
            '',
            'Recommended Next Checks:',
            $this->renderRecommendedNextChecks($report->recommendedNextChecks),
        ])."\n";
    }

    /**
     * @param array<mixed> $evidence
     */
    private function renderEvidence(array $evidence): string
    {
        if ($evidence === []) {
            return '- None';
        }

        try {
            $encodedEvidence = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '- Evidence could not be rendered.';
        }

        return $encodedEvidence;
    }

    /**
     * @param list<string> $recommendedNextChecks
     */
    private function renderRecommendedNextChecks(array $recommendedNextChecks): string
    {
        if ($recommendedNextChecks === []) {
            return '- None';
        }

        $lines = [];

        foreach ($recommendedNextChecks as $recommendedNextCheck) {
            $lines[] = '- '.$recommendedNextCheck;
        }

        return implode("\n", $lines);
    }
}
