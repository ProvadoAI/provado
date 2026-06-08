<?php

declare(strict_types=1);

namespace Provado\Core;

use InvalidArgumentException;

final readonly class SignalSource
{
    public function __construct(public string $value)
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException('SignalSource cannot be empty.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
