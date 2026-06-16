<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests;

use DateTimeImmutable;
use Mquevedob\Provado\Config\ProvadoConfig;
use Mquevedob\Provado\Config\SourceConfig;
use Mquevedob\Provado\Config\SourceCredentials;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Incidents\IncidentReportBuilder;
use Mquevedob\Provado\Patterns\Checkout\CheckoutDegradationPattern;
use Mquevedob\Provado\Patterns\DiagnosticPatternRegistry;
use Mquevedob\Provado\Pipeline\DiagnosticPipeline;
use Mquevedob\Provado\Sources\AdobeCommerce\AdobeCommerceAdapter;
use Mquevedob\Provado\Sources\NewRelic\NewRelicAdapter;
use Mquevedob\Provado\Sources\SourceAdapterRegistry;
use Mquevedob\Provado\Storage\InMemorySignalStore;
use PHPUnit\Framework\TestCase;

class PipelineTest extends TestCase
{
    public function test_full_loop_over_tier_0_fixtures_produces_incident_report(): void
    {
        $result = $this->pipeline()->run(
            $this->config([
                'new_relic' => $this->source('new_relic', enabled: true),
                'adobe_commerce' => $this->source('adobe_commerce', enabled: true),
            ]),
            $this->window(),
        );

        // 3 New Relic + 4 Adobe Commerce fixtures, all within the window.
        $this->assertSame(7, $result->signalCount);

        // All Tier 0 signals share the "store:default" entity, so they correlate
        // into a single group that the checkout-degradation pattern matches.
        $this->assertSame(1, $result->correlationGroupCount);

        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->hasReport());

        $report = $result->report;
        $this->assertNotNull($report);
        // checkout_transaction_slowdown is critical, the highest signal severity in the group.
        $this->assertSame('critical', $report->severity->value);
        $this->assertNotSame([], $report->findings);
        $this->assertNotSame([], $report->recommendedNextChecks);
    }

    public function test_run_with_no_signals_in_window_produces_no_report(): void
    {
        $result = $this->pipeline()->run(
            $this->config([
                'new_relic' => $this->source('new_relic', enabled: true),
                'adobe_commerce' => $this->source('adobe_commerce', enabled: true),
            ]),
            new TimeWindow(
                start: new DateTimeImmutable('2020-01-01T00:00:00+00:00'),
                end: new DateTimeImmutable('2020-01-01T01:00:00+00:00'),
            ),
        );

        $this->assertSame(0, $result->signalCount);
        $this->assertSame(0, $result->correlationGroupCount);
        $this->assertFalse($result->hasReport());
        $this->assertNull($result->report);
    }

    public function test_source_fetch_errors_are_collected_without_aborting_the_run(): void
    {
        $result = $this->pipeline()->run(
            $this->config([
                'new_relic' => $this->source('new_relic', enabled: true, options: ['fixtures' => ['invalid_payload']]),
                'adobe_commerce' => $this->source('adobe_commerce', enabled: false),
            ]),
            $this->window(),
        );

        $this->assertSame(0, $result->signalCount);
        $this->assertFalse($result->hasReport());
        $this->assertTrue($result->hasErrors());
        $this->assertCount(1, $result->errors);
        $this->assertSame('new_relic', $result->errors[0]->sourceName);
    }

    private function pipeline(): DiagnosticPipeline
    {
        return new DiagnosticPipeline(
            adapters: new SourceAdapterRegistry([
                new NewRelicAdapter(),
                new AdobeCommerceAdapter(),
            ]),
            store: new InMemorySignalStore(),
            patterns: new DiagnosticPatternRegistry([
                new CheckoutDegradationPattern(),
            ]),
            reportBuilder: new IncidentReportBuilder(),
        );
    }

    /**
     * @param array<string, SourceConfig> $sources
     */
    private function config(array $sources): ProvadoConfig
    {
        return new ProvadoConfig(true, $sources);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function source(string $name, bool $enabled, array $options = []): SourceConfig
    {
        return new SourceConfig(
            name: $name,
            enabled: $enabled,
            options: $options,
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
