<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Patterns;

use InvalidArgumentException;

final readonly class PatternEvaluationResult
{
    /**
     * @param list<DiagnosticFinding> $findings
     */
    private function __construct(private array $findings)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param array<mixed> $findings
     */
    public static function fromFindings(array $findings): self
    {
        foreach ($findings as $finding) {
            if (! $finding instanceof DiagnosticFinding) {
                throw new InvalidArgumentException('PatternEvaluationResult findings must be DiagnosticFinding instances.');
            }
        }

        return new self(array_values($findings));
    }

    /**
     * @return list<DiagnosticFinding>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    public function hasFindings(): bool
    {
        return $this->findings !== [];
    }
}
