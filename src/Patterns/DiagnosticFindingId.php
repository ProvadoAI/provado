<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Patterns;

use InvalidArgumentException;
use Mquevedob\Provado\Correlation\CorrelationId;

final readonly class DiagnosticFindingId
{
    public function __construct(public string $value)
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException('DiagnosticFindingId cannot be empty.');
        }
    }

    public static function fromPatternAndCorrelation(string $patternId, CorrelationId $correlationId): self
    {
        if (trim($patternId) === '') {
            throw new InvalidArgumentException('DiagnosticFindingId pattern id cannot be empty.');
        }

        return new self(hash('sha256', trim($patternId)."\n".$correlationId->value));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
