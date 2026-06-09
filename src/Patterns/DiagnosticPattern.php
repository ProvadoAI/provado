<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Patterns;

use Mquevedob\Provado\Correlation\CorrelationGroup;

interface DiagnosticPattern
{
    public function id(): string;

    public function name(): string;

    public function supports(CorrelationGroup $group): bool;

    public function evaluate(CorrelationGroup $group): PatternEvaluationResult;
}
