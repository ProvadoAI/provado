<?php

declare(strict_types=1);

namespace Provado\Config;

use InvalidArgumentException;
use JsonSerializable;

final readonly class SourceCredentials implements JsonSerializable
{
    private const REDACTED = '[redacted]';

    /**
     * @param array<string, string> $values
     */
    public function __construct(
        private array $values = [],
    ) {
        foreach ($values as $name => $value) {
            if (! is_string($name) || trim($name) === '') {
                throw new InvalidArgumentException('Source credential names cannot be empty.');
            }

            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException(sprintf('Source credential "%s" cannot be empty.', $name));
            }
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $credentials = [];

        foreach ($values as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (! is_string($value)) {
                throw new InvalidArgumentException(sprintf('Source credential "%s" must be a string.', (string) $name));
            }

            $credentials[(string) $name] = $value;
        }

        return new self($credentials);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function get(string $name): ?string
    {
        return $this->values[$name] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_fill_keys(array_keys($this->values), self::REDACTED);
    }

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return self::REDACTED;
    }
}
