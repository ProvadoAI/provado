<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Incidents;

use InvalidArgumentException;
use Mquevedob\Provado\Patterns\DiagnosticFinding;

final readonly class IncidentReportId
{
    public function __construct(public string $value)
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException('IncidentReportId cannot be empty.');
        }
    }

    /**
     * @param array<mixed> $findings
     */
    public static function fromFindings(array $findings): self
    {
        $findingFingerprints = [];

        foreach ($findings as $finding) {
            if (! $finding instanceof DiagnosticFinding) {
                throw new InvalidArgumentException('IncidentReportId findings must be DiagnosticFinding instances.');
            }

            $findingFingerprints[] = $finding->id->value;
        }

        sort($findingFingerprints, SORT_STRING);

        return new self(hash('sha256', implode("\n", $findingFingerprints)));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
