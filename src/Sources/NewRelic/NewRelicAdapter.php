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

    /**
     * Credentials a credentialed client needs before it can be selected over
     * the fixture fallback.
     *
     * @var list<string>
     */
    private const REQUIRED_CREDENTIALS = ['api_key'];

    private const DEFAULT_FIXTURES = [
        'latency_spike',
        'error_rate_spike',
        'checkout_transaction_slowdown',
    ];

    public function __construct(
        private ?NewRelicFixtureClient $fixtureClient = null,
        private ?NewRelicPayloadMapper $payloadMapper = null,
        private ?NewRelicClient $client = null,
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
        if ($this->client !== null && $config->hasCredentials(...self::REQUIRED_CREDENTIALS)) {
            return $this->client->fetch($config, $window);
        }

        return $this->fetchFromFixtures($config, $window);
    }

    private function fetchFromFixtures(SourceConfig $config, TimeWindow $window): SourceFetchResult
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
