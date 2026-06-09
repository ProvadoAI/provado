<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Sources\NewRelic;

use Mquevedob\Provado\Config\SourceConfig;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Sources\SourceAdapter;
use Mquevedob\Provado\Sources\SourceFetchError;
use Mquevedob\Provado\Sources\SourceFetchResult;
use Throwable;

final readonly class NewRelicAdapter implements SourceAdapter
{
    public const SOURCE_NAME = 'new_relic';

    private const DEFAULT_FIXTURES = [
        'latency_spike',
        'error_rate_spike',
        'checkout_transaction_slowdown',
    ];

    public function __construct(
        private ?NewRelicFixtureClient $fixtureClient = null,
        private ?NewRelicPayloadMapper $payloadMapper = null,
    ) {
    }

    public function name(): string
    {
        return self::SOURCE_NAME;
    }

    public function supports(SourceConfig $config): bool
    {
        return $config->name === self::SOURCE_NAME;
    }

    public function fetch(SourceConfig $config, TimeWindow $window): SourceFetchResult
    {
        $signals = [];
        $errors = [];

        foreach ($this->fixtureNames($config) as $fixtureName) {
            try {
                $payload = $this->fixtureClient()->payload($fixtureName);
                $signal = $this->payloadMapper()->map($payload);

                if ($window->contains($signal->timestamp)) {
                    $signals[] = $signal;
                }
            } catch (Throwable $exception) {
                $errors[] = new SourceFetchError(
                    sourceName: self::SOURCE_NAME,
                    message: 'Unable to map New Relic fixture payload.',
                    code: 'invalid_fixture_payload',
                    retryable: false,
                    context: [
                        'fixture' => $fixtureName,
                        'reason' => $exception->getMessage(),
                    ],
                );
            }
        }

        return new SourceFetchResult($signals, $errors);
    }

    /**
     * @return list<string>
     */
    private function fixtureNames(SourceConfig $config): array
    {
        $fixtures = $config->option('fixtures', self::DEFAULT_FIXTURES);

        if (! is_array($fixtures)) {
            return self::DEFAULT_FIXTURES;
        }

        $fixtureNames = [];

        foreach ($fixtures as $fixture) {
            if (is_string($fixture) && trim($fixture) !== '') {
                $fixtureNames[] = $fixture;
            }
        }

        return $fixtureNames === [] ? self::DEFAULT_FIXTURES : $fixtureNames;
    }

    private function fixtureClient(): NewRelicFixtureClient
    {
        return $this->fixtureClient ?? new NewRelicFixtureClient();
    }

    private function payloadMapper(): NewRelicPayloadMapper
    {
        return $this->payloadMapper ?? new NewRelicPayloadMapper();
    }
}
