<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Core;

use InvalidArgumentException;

final readonly class SignalSeverity
{
    private const ALLOWED_VALUES = [
        'info',
        'warning',
        'error',
        'critical',
    ];

    public function __construct(public string $value)
    {
        $normalizedValue = strtolower(trim($value));

        if ($normalizedValue === '') {
            throw new InvalidArgumentException('SignalSeverity cannot be empty.');
        }

        if (! in_array($normalizedValue, self::ALLOWED_VALUES, true)) {
            throw new InvalidArgumentException('SignalSeverity must be one of: '.implode(', ', self::ALLOWED_VALUES).'.');
        }

        if ($normalizedValue !== $value) {
            throw new InvalidArgumentException('SignalSeverity must be lowercase and trimmed.');
        }
    }

    public static function info(): self
    {
        return new self('info');
    }

    public static function warning(): self
    {
        return new self('warning');
    }

    public static function error(): self
    {
        return new self('error');
    }

    public static function critical(): self
    {
        return new self('critical');
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
