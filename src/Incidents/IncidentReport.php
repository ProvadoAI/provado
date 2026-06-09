<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Incidents;

use DateTimeImmutable;
use InvalidArgumentException;
use Mquevedob\Provado\Patterns\DiagnosticFinding;

final readonly class IncidentReport
{
    private const REDACTED = '[redacted]';

    private const SECRET_KEY_FRAGMENTS = [
        'api_key',
        'apikey',
        'access_token',
        'authorization',
        'bearer',
        'credential',
        'password',
        'secret',
        'token',
    ];

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
        $this->evidence = $this->sanitizeEvidence($evidence);
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

    /**
     * @param array<mixed> $evidence
     * @return array<mixed>
     */
    private function sanitizeEvidence(array $evidence): array
    {
        $sanitized = [];

        foreach ($evidence as $name => $value) {
            if (is_string($name) && $this->isSecretKey($name)) {
                $sanitized[$name] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $sanitized[$name] = $this->sanitizeEvidence($value);

                continue;
            }

            $sanitized[$name] = $value;
        }

        return $sanitized;
    }

    private function isSecretKey(string $name): bool
    {
        $normalized = strtolower($name);

        foreach (self::SECRET_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
