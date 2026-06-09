<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Sources;

use InvalidArgumentException;
use Mquevedob\Provado\Core\Signal;

final readonly class SourceFetchResult
{
    /**
     * @param list<Signal> $signals
     * @param list<SourceFetchError> $errors
     */
    public function __construct(
        private array $signals,
        private array $errors,
    ) {
        foreach ($signals as $signal) {
            if (! $signal instanceof Signal) {
                throw new InvalidArgumentException('Source fetch result signals must be Signal instances.');
            }
        }

        foreach ($errors as $error) {
            if (! $error instanceof SourceFetchError) {
                throw new InvalidArgumentException('Source fetch result errors must be SourceFetchError instances.');
            }
        }
    }

    /**
     * @return list<Signal>
     */
    public function signals(): array
    {
        return $this->signals;
    }

    /**
     * @return list<SourceFetchError>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * @param list<Signal> $signals
     */
    public static function fromSignals(array $signals): self
    {
        return new self($signals, []);
    }

    /**
     * @param list<SourceFetchError> $errors
     */
    public function withErrors(array $errors): self
    {
        return new self($this->signals, $errors);
    }
}
