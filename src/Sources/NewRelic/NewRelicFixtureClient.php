<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Sources\NewRelic;

use RuntimeException;

final readonly class NewRelicFixtureClient
{
    public function __construct(
        private ?string $fixtureDirectory = null,
    ) {
    }

    /**
     * @param list<string> $fixtureNames
     * @return list<array<string, mixed>>
     */
    public function payloads(array $fixtureNames): array
    {
        $payloads = [];

        foreach ($fixtureNames as $fixtureName) {
            $payloads[] = $this->payload($fixtureName);
        }

        return $payloads;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(string $fixtureName): array
    {
        $path = $this->fixturePath($fixtureName);
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read New Relic fixture "%s".', $fixtureName));
        }

        $payload = json_decode($contents, true);

        if (! is_array($payload)) {
            throw new RuntimeException(sprintf('New Relic fixture "%s" must contain a JSON object.', $fixtureName));
        }

        return $payload;
    }

    private function fixturePath(string $fixtureName): string
    {
        $normalizedFixtureName = basename($fixtureName, '.json').'.json';

        return $this->fixtureDirectory().DIRECTORY_SEPARATOR.$normalizedFixtureName;
    }

    private function fixtureDirectory(): string
    {
        return $this->fixtureDirectory ?? getcwd().DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'new_relic';
    }
}
