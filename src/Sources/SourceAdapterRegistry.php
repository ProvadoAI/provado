<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Sources;

use InvalidArgumentException;
use Mquevedob\Provado\Config\ProvadoConfig;

final readonly class SourceAdapterRegistry
{
    /**
     * @var array<string, SourceAdapter>
     */
    private array $adapters;

    /**
     * @param iterable<mixed> $adapters
     */
    public function __construct(iterable $adapters)
    {
        $indexedAdapters = [];

        foreach ($adapters as $adapter) {
            if (! $adapter instanceof SourceAdapter) {
                throw new InvalidArgumentException('Source adapter registry entries must implement SourceAdapter.');
            }

            $name = $adapter->name();

            if (trim($name) === '') {
                throw new InvalidArgumentException('Source adapter names cannot be empty.');
            }

            $indexedAdapters[$name] = $adapter;
        }

        $this->adapters = $indexedAdapters;
    }

    public function adapter(string $name): SourceAdapter
    {
        if (! isset($this->adapters[$name])) {
            throw new InvalidArgumentException(sprintf('Source adapter "%s" is not registered.', $name));
        }

        return $this->adapters[$name];
    }

    /**
     * @return array<string, SourceAdapter>
     */
    public function enabledAdaptersFor(ProvadoConfig $config): array
    {
        if (! $config->enabled) {
            return [];
        }

        $enabledAdapters = [];

        foreach ($config->sources() as $source) {
            if (! $source->enabled) {
                continue;
            }

            $adapter = $this->adapter($source->name);

            if (! $adapter->supports($source)) {
                continue;
            }

            $enabledAdapters[$source->name] = $adapter;
        }

        return $enabledAdapters;
    }
}
