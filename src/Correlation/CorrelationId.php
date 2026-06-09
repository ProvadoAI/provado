<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Correlation;

use InvalidArgumentException;
use Mquevedob\Provado\Core\Signal;

final readonly class CorrelationId
{
    public function __construct(public string $value)
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException('CorrelationId cannot be empty.');
        }
    }

    /**
     * @param list<Signal> $signals
     */
    public static function fromSignals(array $signals): self
    {
        if ($signals === []) {
            throw new InvalidArgumentException('CorrelationId requires at least one signal.');
        }

        $signalIds = [];

        foreach ($signals as $signal) {
            if (! $signal instanceof Signal) {
                throw new InvalidArgumentException('CorrelationId requires Signal instances.');
            }

            $signalIds[] = $signal->id->value;
        }

        sort($signalIds, SORT_STRING);

        return new self(hash('sha256', implode("\n", $signalIds)));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
