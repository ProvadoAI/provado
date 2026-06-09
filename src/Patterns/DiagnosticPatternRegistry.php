<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Patterns;

use InvalidArgumentException;
use Mquevedob\Provado\Correlation\CorrelationGroup;

final readonly class DiagnosticPatternRegistry
{
    /**
     * @var array<string, DiagnosticPattern>
     */
    private array $patterns;

    /**
     * @param array<mixed> $patterns
     */
    public function __construct(array $patterns = [])
    {
        $registered = [];

        foreach ($patterns as $pattern) {
            if (! $pattern instanceof DiagnosticPattern) {
                throw new InvalidArgumentException('Diagnostic pattern registry entries must implement DiagnosticPattern.');
            }

            $patternId = $pattern->id();

            if (trim($patternId) === '') {
                throw new InvalidArgumentException('Diagnostic pattern id cannot be empty.');
            }

            if (trim($patternId) !== $patternId) {
                throw new InvalidArgumentException('Diagnostic pattern id must be trimmed.');
            }

            if (array_key_exists($patternId, $registered)) {
                throw new InvalidArgumentException(sprintf('Duplicate diagnostic pattern id "%s".', $patternId));
            }

            $registered[$patternId] = $pattern;
        }

        $this->patterns = $registered;
    }

    /**
     * @return array<string, DiagnosticPattern>
     */
    public function all(): array
    {
        return $this->patterns;
    }

    /**
     * @return array<string, DiagnosticPattern>
     */
    public function matching(CorrelationGroup $group): array
    {
        $matching = [];

        foreach ($this->patterns as $patternId => $pattern) {
            if ($pattern->supports($group)) {
                $matching[$patternId] = $pattern;
            }
        }

        return $matching;
    }

    /**
     * @return list<PatternEvaluationResult>
     */
    public function evaluate(CorrelationGroup $group): array
    {
        $results = [];

        foreach ($this->matching($group) as $pattern) {
            $results[] = $pattern->evaluate($group);
        }

        return $results;
    }
}
