<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Patterns;

use InvalidArgumentException;
use Mquevedob\Provado\Correlation\CorrelationId;

final readonly class DiagnosticFinding
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
     * @var array<mixed>
     */
    public array $evidence;

    /**
     * @var array<mixed>
     */
    public array $recommendedNextChecks;

    /**
     * @param array<mixed> $evidence
     * @param array<mixed> $recommendedNextChecks
     */
    public function __construct(
        public DiagnosticFindingId $id,
        public string $patternId,
        public string $title,
        public string $summary,
        public DiagnosticFindingSeverity $severity,
        public CorrelationId $correlationId,
        array $evidence = [],
        array $recommendedNextChecks = [],
    ) {
        if (trim($patternId) === '') {
            throw new InvalidArgumentException('DiagnosticFinding pattern id cannot be empty.');
        }

        if (trim($title) === '') {
            throw new InvalidArgumentException('DiagnosticFinding title cannot be empty.');
        }

        if (trim($summary) === '') {
            throw new InvalidArgumentException('DiagnosticFinding summary cannot be empty.');
        }

        $this->evidence = $this->sanitizeEvidence($evidence);
        $this->recommendedNextChecks = $recommendedNextChecks;
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
