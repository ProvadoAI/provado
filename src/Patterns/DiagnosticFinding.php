<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Patterns;

use InvalidArgumentException;
use Mquevedob\Provado\Correlation\CorrelationId;
use Mquevedob\Provado\Support\ContextRedactor;

final readonly class DiagnosticFinding
{
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

        $this->evidence = (new ContextRedactor())->redact($evidence);
        $this->recommendedNextChecks = $recommendedNextChecks;
    }
}
