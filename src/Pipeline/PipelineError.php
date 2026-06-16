<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Pipeline;

use InvalidArgumentException;
use Mquevedob\Provado\Support\ContextRedactor;

/**
 * A failure captured while running a pipeline stage (correlation, pattern
 * evaluation, report building). Recorded rather than thrown so one failing
 * stage does not abort the whole run.
 */
final readonly class PipelineError
{
    /**
     * @var array<string, mixed>
     */
    public array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $stage,
        public string $message,
        public ?string $code = null,
        array $context = [],
    ) {
        if (trim($stage) === '') {
            throw new InvalidArgumentException('Pipeline error stage cannot be empty.');
        }

        if (trim($message) === '') {
            throw new InvalidArgumentException('Pipeline error message cannot be empty.');
        }

        foreach (array_keys($context) as $contextName) {
            if (! is_string($contextName) || trim($contextName) === '') {
                throw new InvalidArgumentException('Pipeline error context names cannot be empty.');
            }
        }

        $this->context = (new ContextRedactor())->redact($context);
    }
}
