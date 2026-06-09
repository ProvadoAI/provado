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

    private const DEFAULT_FIXTURES = [
        'checkout_failure_rate',
        'order_sync_backlog',
        'inventory_sync_drift',
        'indexer_stuck',
    ];

    public function __construct(
        private ?AdobeCommerceFixtureClient $fixtureClient = null,
        private ?AdobeCommercePayloadMapper $payloadMapper = null,
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
