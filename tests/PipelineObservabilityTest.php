<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests;

use DateTimeImmutable;
use Mquevedob\Provado\Config\ProvadoConfig;
use Mquevedob\Provado\Config\SourceConfig;
use Mquevedob\Provado\Config\SourceCredentials;
use Mquevedob\Provado\Core\EntityReference;
use Mquevedob\Provado\Core\RawPayloadReference;
use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Core\SignalId;
use Mquevedob\Provado\Core\SignalSeverity;
use Mquevedob\Provado\Core\SignalSource;
use Mquevedob\Provado\Core\SignalType;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Incidents\IncidentReportBuilder;
use Mquevedob\Provado\Patterns\Checkout\CheckoutDegradationPattern;
use Mquevedob\Provado\Patterns\DiagnosticPatternRegistry;
use Mquevedob\Provado\Pipeline\DiagnosticPipeline;
use Mquevedob\Provado\Pipeline\FixedRetryPolicy;
use Mquevedob\Provado\Pipeline\InMemoryPipelineObserver;
use Mquevedob\Provado\Pipeline\NoRetryPolicy;
use Mquevedob\Provado\Pipeline\PsrLoggerObserver;
use Mquevedob\Provado\Sources\AdobeCommerce\AdobeCommerceAdapter;
use Mquevedob\Provado\Sources\NewRelic\NewRelicAdapter;
use Mquevedob\Provado\Sources\SourceAdapter;
use Mquevedob\Provado\Sources\SourceAdapterRegistry;
use Mquevedob\Provado\Sources\SourceFetchError;
use Mquevedob\Provado\Sources\SourceFetchResult;
use Mquevedob\Provado\Storage\InMemorySignalStoreFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

class PipelineObservabilityTest extends TestCase
{
    public function test_observer_records_stage_events_for_a_full_run(): void
    {
        $observer = new InMemoryPipelineObserver();

        $this->fixturePipeline($observer)->run($this->fullConfig(), $this->window());

        $names = $observer->eventNames();

        $this->assertSame('run_started', $names[0]);
        $this->assertSame('run_completed', $names[array_key_last($names)]);
        $this->assertSame(2, $this->countEvents($observer, 'source_fetched'));
        $this->assertContains('correlation_completed', $names);
        $this->assertContains('pattern_evaluated', $names);
    }

    public function test_psr_logger_observer_logs_run_lifecycle(): void
    {
        $logger = new RecordingLogger();

        $pipeline = $this->fixturePipeline(new PsrLoggerObserver($logger));
        $pipeline->run($this->fullConfig(), $this->window());

        $messages = array_map(static fn (array $record): string => $record['message'], $logger->records);

        $this->assertContains('Pipeline run started.', $messages);
        $this->assertContains('Pipeline source fetched.', $messages);
        $this->assertContains('Pipeline run completed.', $messages);
    }

    public function test_retry_policy_recovers_a_transient_source_failure(): void
    {
        $adapter = new FlakySourceAdapter();

        $pipeline = new DiagnosticPipeline(
            adapters: new SourceAdapterRegistry([$adapter]),
            storeFactory: new InMemorySignalStoreFactory(),
            patterns: new DiagnosticPatternRegistry([new CheckoutDegradationPattern()]),
            reportBuilder: new IncidentReportBuilder(),
            retryPolicy: new FixedRetryPolicy(2),
        );

        $result = $pipeline->run($this->flakyConfig(), $this->window());

        $this->assertSame(2, $adapter->attempts);
        $this->assertSame(1, $result->diagnostics->signalCount);
        $this->assertFalse($result->hasErrors());
    }

    public function test_no_retry_policy_leaves_the_transient_failure_in_place(): void
    {
        $adapter = new FlakySourceAdapter();

        $pipeline = new DiagnosticPipeline(
            adapters: new SourceAdapterRegistry([$adapter]),
            storeFactory: new InMemorySignalStoreFactory(),
            patterns: new DiagnosticPatternRegistry([new CheckoutDegradationPattern()]),
            reportBuilder: new IncidentReportBuilder(),
            retryPolicy: new NoRetryPolicy(),
        );

        $result = $pipeline->run($this->flakyConfig(), $this->window());

        $this->assertSame(1, $adapter->attempts);
        $this->assertSame(0, $result->diagnostics->signalCount);
        $this->assertTrue($result->hasErrors());
    }

    private function fixturePipeline(object $observer): DiagnosticPipeline
    {
        return new DiagnosticPipeline(
            adapters: new SourceAdapterRegistry([
                new NewRelicAdapter(),
                new AdobeCommerceAdapter(),
            ]),
            storeFactory: new InMemorySignalStoreFactory(),
            patterns: new DiagnosticPatternRegistry([new CheckoutDegradationPattern()]),
            reportBuilder: new IncidentReportBuilder(),
            observer: $observer,
        );
    }

    private function countEvents(InMemoryPipelineObserver $observer, string $event): int
    {
        return count(array_filter($observer->eventNames(), static fn (string $name): bool => $name === $event));
    }

    private function fullConfig(): ProvadoConfig
    {
        return new ProvadoConfig(true, [
            'new_relic' => $this->source('new_relic'),
            'adobe_commerce' => $this->source('adobe_commerce'),
        ]);
    }

    private function flakyConfig(): ProvadoConfig
    {
        return new ProvadoConfig(true, ['flaky' => $this->source('flaky')]);
    }

    private function source(string $name): SourceConfig
    {
        return new SourceConfig(
            name: $name,
            enabled: true,
            options: [],
            credentials: new SourceCredentials(),
        );
    }

    private function window(): TimeWindow
    {
        return new TimeWindow(
            start: new DateTimeImmutable('2026-06-08T12:00:00+00:00'),
            end: new DateTimeImmutable('2026-06-08T12:30:00+00:00'),
        );
    }
}

final class FlakySourceAdapter implements SourceAdapter
{
    public int $attempts = 0;

    public function name(): string
    {
        return 'flaky';
    }

    public function supports(SourceConfig $config): bool
    {
        return $config->name === 'flaky';
    }

    public function fetch(SourceConfig $config, TimeWindow $window): SourceFetchResult
    {
        $this->attempts++;

        if ($this->attempts === 1) {
            return SourceFetchResult::empty()->withErrors([
                new SourceFetchError(
                    sourceName: 'flaky',
                    message: 'Transient failure.',
                    code: 'transient',
                    retryable: true,
                ),
            ]);
        }

        return SourceFetchResult::fromSignals([
            new Signal(
                id: new SignalId('flaky:1'),
                source: new SignalSource('flaky'),
                type: new SignalType('latency_spike'),
                timestamp: new DateTimeImmutable('2026-06-08T12:05:00+00:00'),
                severity: SignalSeverity::warning(),
                entityReferences: [new EntityReference('service', 'checkout-api')],
                attributes: ['duration_ms' => 1200],
                rawPayloadReference: new RawPayloadReference('flaky-1'),
            ),
        ]);
    }
}

final class RecordingLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<mixed>}>
     */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
