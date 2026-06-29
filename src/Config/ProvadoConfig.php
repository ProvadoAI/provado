<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Config;

use InvalidArgumentException;
use JsonSerializable;

final readonly class ProvadoConfig implements JsonSerializable
{
    /**
     * @var array<string, array{required_options: list<string>, required_credentials: list<string>}>
     */
    private const SOURCE_REQUIREMENTS = [
        'new_relic' => [
            'required_options' => ['account_id'],
            'required_credentials' => ['api_key'],
        ],
        'adobe_commerce' => [
            'required_options' => ['base_url'],
            'required_credentials' => ['consumer_key', 'consumer_secret', 'access_token', 'access_token_secret'],
        ],
    ];

    /**
     * @param array<string, SourceConfig> $sources
     */
    public function __construct(
        public bool $enabled,
        private array $sources,
    ) {
        foreach ($sources as $name => $source) {
            if (! is_string($name) || trim($name) === '') {
                throw new InvalidArgumentException('Configured source names cannot be empty.');
            }

            if (! $source instanceof SourceConfig) {
                throw new InvalidArgumentException('Configured sources must be SourceConfig instances.');
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $enabled = (bool) ($config['enabled'] ?? true);
        $sourceConfigs = $config['sources'] ?? [];

        if (! is_array($sourceConfigs)) {
            throw new InvalidArgumentException('Provado sources config must be an array.');
        }

        $sources = [];

        foreach (array_keys(self::SOURCE_REQUIREMENTS) as $sourceName) {
            $sourceConfig = $sourceConfigs[$sourceName] ?? [];

            if (! is_array($sourceConfig)) {
                throw new InvalidArgumentException(sprintf('Source "%s" config must be an array.', $sourceName));
            }

            $sources[$sourceName] = SourceConfig::fromArray($sourceName, $sourceConfig);
        }

        foreach ($sourceConfigs as $sourceName => $_sourceConfig) {
            if (! is_string($sourceName) || ! array_key_exists($sourceName, self::SOURCE_REQUIREMENTS)) {
                throw new InvalidArgumentException(sprintf('Unsupported Provado source "%s".', (string) $sourceName));
            }
        }

        $provadoConfig = new self($enabled, $sources);

        if ($enabled) {
            $provadoConfig->validateEnabledSources();
        }

        return $provadoConfig;
    }

    public function source(string $name): SourceConfig
    {
        if (! isset($this->sources[$name])) {
            throw new InvalidArgumentException(sprintf('Provado source "%s" is not configured.', $name));
        }

        return $this->sources[$name];
    }

    /**
     * @return array<string, SourceConfig>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * @return array{enabled: bool, sources: array<string, array{name: string, enabled: bool, options: array<string, mixed>, credentials: array<string, string>}>}
     */
    public function toArray(): array
    {
        $sources = [];

        foreach ($this->sources as $name => $source) {
            $sources[$name] = $source->toArray();
        }

        return [
            'enabled' => $this->enabled,
            'sources' => $sources,
        ];
    }

    /**
     * @return array{enabled: bool, sources: array<string, array{name: string, enabled: bool, options: array<string, mixed>, credentials: array<string, string>}>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function validateEnabledSources(): void
    {
        foreach ($this->sources as $source) {
            if (! $source->enabled) {
                continue;
            }

            $requirements = self::SOURCE_REQUIREMENTS[$source->name];

            foreach ($requirements['required_options'] as $requiredOption) {
                if (! $this->hasRequiredValue($source->option($requiredOption))) {
                    throw new InvalidArgumentException(sprintf('Source "%s" is missing required option "%s".', $source->name, $requiredOption));
                }
            }

            foreach ($requirements['required_credentials'] as $requiredCredential) {
                if (! $source->credentials->has($requiredCredential)) {
                    throw new InvalidArgumentException(sprintf('Source "%s" is missing required credential "%s".', $source->name, $requiredCredential));
                }
            }
        }
    }

    private function hasRequiredValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return true;
    }
}
