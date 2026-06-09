<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Core;

use InvalidArgumentException;

final readonly class SignalType
{
    public function __construct(public string $value)
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException('SignalType cannot be empty.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
