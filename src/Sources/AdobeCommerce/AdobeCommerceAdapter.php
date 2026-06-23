<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Sources\AdobeCommerce;

use Mquevedob\Provado\Config\SourceConfig;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Sources\SourceAdapter;
use Mquevedob\Provado\Sources\SourceFetchError;
use Mquevedob\Provado\Sources\SourceFetchResult;
use Throwable;

final readonly class AdobeCommerceAdapter implements SourceAdapter
{
    public const SOURCE_NAME = 'adobe_commerce';

    /**
     * Credentials a credentialed client needs before it can be selected over
     * the fixture fallback.
     *
     * @var list<string>
     */
    private const REQUIRED_CREDENTIALS = ['access_token'];

    private const DEFAULT_FIXTURES = [
        'checkout_failure_rate',
        'order_sync_backlog',
        'inventory_sync_drift',
        'indexer_stuck',
    ];

    public function __construct(
        private ?AdobeCommerceFixtureClient $fixtureClient = null,
        private ?AdobeCommercePayloadMapper $payloadMapper = null,
        private ?AdobeCommerceClient $client = null,
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
                    message: 'Unable to map Adobe Commerce fixture payload.',
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

    private function fixtureClient(): AdobeCommerceFixtureClient
    {
        return $this->fixtureClient ?? new AdobeCommerceFixtureClient();
    }

    private function payloadMapper(): AdobeCommercePayloadMapper
    {
        return $this->payloadMapper ?? new AdobeCommercePayloadMapper();
    }
}
