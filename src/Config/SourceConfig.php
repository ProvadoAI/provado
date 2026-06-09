<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Config;

use InvalidArgumentException;
use JsonSerializable;

final readonly class SourceConfig implements JsonSerializable
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $name,
        public bool $enabled,
        public array $options,
        public SourceCredentials $credentials,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Source name cannot be empty.');
        }

        foreach (array_keys($options) as $optionName) {
            if (! is_string($optionName) || trim($optionName) === '') {
                throw new InvalidArgumentException('Source option names cannot be empty.');
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(string $name, array $config): self
    {
        $options = $config['options'] ?? [];
        $credentials = $config['credentials'] ?? [];

        if (! is_array($options)) {
            throw new InvalidArgumentException(sprintf('Source "%s" options must be an array.', $name));
        }

        if (! is_array($credentials)) {
            throw new InvalidArgumentException(sprintf('Source "%s" credentials must be an array.', $name));
        }

        return new self(
            name: $name,
            enabled: (bool) ($config['enabled'] ?? false),
            options: $options,
            credentials: SourceCredentials::fromArray($credentials),
        );
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    /**
     * @return array{name: string, enabled: bool, options: array<string, mixed>, credentials: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'enabled' => $this->enabled,
            'options' => $this->options,
            'credentials' => $this->credentials->toArray(),
        ];
    }

    /**
     * @return array{name: string, enabled: bool, options: array<string, mixed>, credentials: array<string, string>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
