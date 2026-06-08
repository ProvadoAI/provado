<?php

declare(strict_types=1);

namespace Provado\Core;

use InvalidArgumentException;

final readonly class SignalId
{
    public function __construct(public string $value)
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException('SignalId cannot be empty.');
        }
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
