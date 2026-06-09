<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Incidents;

use InvalidArgumentException;
use Mquevedob\Provado\Patterns\DiagnosticFinding;

final readonly class IncidentReportSeverity
{
    private const ALLOWED_VALUES = [
        'info',
        'warning',
        'error',
        'critical',
    ];

    private const RANKS = [
        'info' => 0,
        'warning' => 1,
        'error' => 2,
        'critical' => 3,
    ];

    public function __construct(public string $value)
    {
        $normalizedValue = strtolower(trim($value));

        if ($normalizedValue === '') {
            throw new InvalidArgumentException('IncidentReportSeverity cannot be empty.');
        }

        if (! in_array($normalizedValue, self::ALLOWED_VALUES, true)) {
            throw new InvalidArgumentException('IncidentReportSeverity must be one of: '.implode(', ', self::ALLOWED_VALUES).'.');
        }

        if ($normalizedValue !== $value) {
            throw new InvalidArgumentException('IncidentReportSeverity must be lowercase and trimmed.');
        }
    }

    public static function info(): self
    {
        return new self('info');
    }

    public static function warning(): self
    {
        return new self('warning');
    }

    public static function error(): self
    {
        return new self('error');
    }

    public static function critical(): self
    {
        return new self('critical');
    }

    /**
     * @param array<mixed> $findings
     */
    public static function fromFindings(array $findings): self
    {
        $highest = 'info';

        foreach ($findings as $finding) {
            if (! $finding instanceof DiagnosticFinding) {
                throw new InvalidArgumentException('IncidentReportSeverity findings must be DiagnosticFinding instances.');
            }

            if (self::RANKS[$finding->severity->value] > self::RANKS[$highest]) {
                $highest = $finding->severity->value;
            }
        }

        return new self($highest);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
