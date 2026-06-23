<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Incidents;

use DateTimeImmutable;
use InvalidArgumentException;
use Mquevedob\Provado\Patterns\DiagnosticFinding;
use Mquevedob\Provado\Patterns\DiagnosticFindingSeverity;

final readonly class IncidentReportBuilder
{
    /**
     * @param array<mixed> $findings
     */
    public function build(array $findings, ?DateTimeImmutable $createdAt = null): ?IncidentReport
    {
        if ($findings === []) {
            return null;
        }

        $validatedFindings = $this->validateFindings($findings);

        return new IncidentReport(
            id: IncidentReportId::fromFindings($validatedFindings),
            title: $this->title($validatedFindings),
            summary: $this->summary($validatedFindings),
            severity: IncidentReportSeverity::fromFindings($validatedFindings),
            findings: $validatedFindings,
            evidence: $this->evidence($validatedFindings),
            recommendedNextChecks: $this->recommendedNextChecks($validatedFindings),
            createdAt: $createdAt ?? new DateTimeImmutable(),
        );
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
                throw new InvalidArgumentException('IncidentReportBuilder findings must be DiagnosticFinding instances.');
            }

            $validated[] = $finding;
        }

        return $validated;
    }

    /**
     * @param list<DiagnosticFinding> $findings
     */
    private function title(array $findings): string
    {
        if (count($findings) === 1) {
            return $findings[0]->title;
        }

        return 'Multiple diagnostic findings detected';
    }

    /**
     * @param list<DiagnosticFinding> $findings
     */
    private function summary(array $findings): string
    {
        if (count($findings) === 1) {
            return $findings[0]->summary;
        }

        $findingTitles = [];

        foreach ($findings as $finding) {
            $findingTitles[] = $finding->title;
        }

        return count($findings).' diagnostic findings were detected: '.implode('; ', $findingTitles).'.';
    }

    /**
     * Aggregate per-finding evidence, de-duplicated by finding id and ordered
     * by finding severity (most severe first) so the report leads with the
     * highest-impact evidence. Ties keep their original order (stable sort).
     *
     * @param list<DiagnosticFinding> $findings
     * @return list<array{finding_id: string, title: string, evidence: array<mixed>}>
     */
    private function evidence(array $findings): array
    {
        $uniqueFindings = [];
        $seen = [];

        foreach ($findings as $finding) {
            $findingId = $finding->id->value;

            if (isset($seen[$findingId])) {
                continue;
            }

            $seen[$findingId] = true;
            $uniqueFindings[] = $finding;
        }

        usort(
            $uniqueFindings,
            fn (DiagnosticFinding $a, DiagnosticFinding $b): int =>
                $this->severityRank($b->severity) <=> $this->severityRank($a->severity),
        );

        $evidence = [];

        foreach ($uniqueFindings as $finding) {
            $evidence[] = [
                'finding_id' => $finding->id->value,
                'title' => $finding->title,
                'evidence' => $finding->evidence,
            ];
        }

        return $evidence;
    }

    private function severityRank(DiagnosticFindingSeverity $severity): int
    {
        return match ($severity->value) {
            'info' => 10,
            'warning' => 20,
            'error' => 30,
            'critical' => 40,
        };
    }

    /**
     * @param list<DiagnosticFinding> $findings
     * @return list<string>
     */
    private function recommendedNextChecks(array $findings): array
    {
        $checks = [];
        $seen = [];

        foreach ($findings as $finding) {
            foreach ($finding->recommendedNextChecks as $recommendedNextCheck) {
                if (! is_string($recommendedNextCheck)) {
                    throw new InvalidArgumentException('IncidentReportBuilder recommended next checks must be strings.');
                }

                if (isset($seen[$recommendedNextCheck])) {
                    continue;
                }

                $seen[$recommendedNextCheck] = true;
                $checks[] = $recommendedNextCheck;
            }
        }

        return $checks;
    }
}
