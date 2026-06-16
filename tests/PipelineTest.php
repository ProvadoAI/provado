<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests;

use DateTimeImmutable;
use Mquevedob\Provado\Config\ProvadoConfig;
use Mquevedob\Provado\Config\SourceConfig;
use Mquevedob\Provado\Config\SourceCredentials;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Correlation\CorrelationGroup;
use Mquevedob\Provado\Incidents\IncidentReportBuilder;
use Mquevedob\Provado\Patterns\Checkout\CheckoutDegradationPattern;
use Mquevedob\Provado\Patterns\DiagnosticPattern;
use Mquevedob\Provado\Patterns\DiagnosticPatternRegistry;
use Mquevedob\Provado\Patterns\PatternEvaluationResult;
use Mquevedob\Provado\Pipeline\DiagnosticPipeline;
use Mquevedob\Provado\Sources\AdobeCommerce\AdobeCommerceAdapter;
use Mquevedob\Provado\Sources\NewRelic\NewRelicAdapter;
use Mquevedob\Provado\Sources\SourceAdapterRegistry;
use Mquevedob\Provado\Storage\InMemorySignalStoreFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PipelineTest extends TestCase
{
    public function test_full_loop_over_tier_0_fixtures_produces_incident_report(): void
    {
        $result = $this->pipeline()->run($this->fullConfig(), $this->window());

        // 3 New Relic + 4 Adobe Commerce fixtures, all within the window.
        $this->assertSame(7, $result->diagnostics->signalCount);

        // All Tier 0 signals share the "store:default" entity, so they correlate
        // into a single group that the checkout-degradation pattern matches.
        $this->assertSame(1, $result->diagnostics->correlationGroupCount);
        $this->assertSame(1, $result->diagnostics->patternsMatched);
        $this->assertSame(1, $result->diagnostics->patternsEvaluated);
        $this->assertTrue($result->diagnostics->reportProduced);

        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->hasReport());

        $report = $result->report;
        $this->assertNotNull($report);
        // checkout_transaction_slowdown is critical, the highest signal severity in the group.
        $this->assertSame('critical', $report->severity->value);
        $this->assertNotSame([], $report->findings);
        $this->assertNotSame([], $report->recommendedNextChecks);
    }

    public function test_repeated_runs_are_self_contained(): void
    {
        $pipeline = $this->pipeline();

        $first = $pipeline->run($this->fullConfig(), $this->window());
        $second = $pipeline->run($this->fullConfig(), $this->window());

        // A reused pipeline must not accumulate signals from the previous run.
        $this->assertSame(7, $first->diagnostics->signalCount);
        $this->assertSame(7, $second->diagnostics->signalCount);
        $this->assertSame(1, $second->diagnostics->correlationGroupCount);
    }

    public function test_diagnostics_report_per_source_summaries_and_stage_timings(): void
    {
        $result = $this->pipeline()->run($this->fullConfig(), $this->window());

        $signalsBySource = [];
        foreach ($result->diagnostics->sources as $summary) {
            $signalsBySource[$summary->sourceName] = $summary->signalCount;
            $this->assertSame(0, $summary->errorCount);
        }

        $this->assertSame(['new_relic' => 3, 'adobe_commerce' => 4], $signalsBySource);

        $this->assertArrayHasKey('fetch', $result->diagnostics->stageDurationsMs);
        $this->assertArrayHasKey('correlation', $result->diagnostics->stageDurationsMs);
        $this->assertArrayHasKey('pattern_evaluation', $result->diagnostics->stageDurationsMs);
        $this->assertArrayHasKey('report', $result->diagnostics->stageDurationsMs);

        foreach ($result->diagnostics->stageDurationsMs as $duration) {
            $this->assertGreaterThanOrEqual(0.0, $duration);
        }
    }

    public function test_no_signals_in_window_produces_no_report(): void
    {
        $result = $this->pipeline()->run(
            $this->fullConfig(),
            new TimeWindow(
                start: new DateTimeImmutable('2020-01-01T00:00:00+00:00'),
                end: new DateTimeImmutable('2020-01-01T01:00:00+00:00'),
            ),
        );

        $this->assertSame(0, $result->diagnostics->signalCount);
        $this->assertSame(0, $result->diagnostics->correlationGroupCount);
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

        $this->assertSame(0, $result->diagnostics->signalCount);
        $this->assertFalse($result->hasReport());
        $this->assertTrue($result->hasErrors());
        $this->assertCount(1, $result->errors);
        $this->assertSame('new_relic', $result->errors[0]->sourceName);
    }

    public function test_a_failing_pattern_is_isolated_and_a_report_still_builds(): void
    {
        $pipeline = new DiagnosticPipeline(
            adapters: new SourceAdapterRegistry([
                new NewRelicAdapter(),
                new AdobeCommerceAdapter(),
            ]),
            storeFactory: new InMemorySignalStoreFactory(),
            patterns: new DiagnosticPatternRegistry([
                new ExplodingPattern(),
                new CheckoutDegradationPattern(),
            ]),
            reportBuilder: new IncidentReportBuilder(),
        );

        $result = $pipeline->run($this->fullConfig(), $this->window());

        // The exploding pattern fails for its group, but the run continues and the
        // healthy checkout-degradation pattern still yields a report.
        $this->assertCount(1, $result->stageErrors);
        $this->assertSame('pattern_evaluation', $result->stageErrors[0]->stage);
        $this->assertSame('exploding', $result->stageErrors[0]->context['pattern']);
        $this->assertTrue($result->hasReport());
        $this->assertSame('critical', $result->report?->severity->value);
    }

    public function test_a_pattern_that_throws_during_support_check_is_isolated(): void
    {
        $pipeline = new DiagnosticPipeline(
            adapters: new SourceAdapterRegistry([
                new NewRelicAdapter(),
                new AdobeCommerceAdapter(),
            ]),
            storeFactory: new InMemorySignalStoreFactory(),
            patterns: new DiagnosticPatternRegistry([
                new ExplodingSupportsPattern(),
                new CheckoutDegradationPattern(),
            ]),
            reportBuilder: new IncidentReportBuilder(),
        );

        $result = $pipeline->run($this->fullConfig(), $this->window());

        $this->assertCount(1, $result->stageErrors);
        $this->assertSame('pattern_evaluation', $result->stageErrors[0]->stage);
        $this->assertSame('exploding_supports', $result->stageErrors[0]->context['pattern']);
        $this->assertTrue($result->hasReport());
    }

    private function pipeline(): DiagnosticPipeline
    {
        return new DiagnosticPipeline(
            adapters: new SourceAdapterRegistry([
                new NewRelicAdapter(),
                new AdobeCommerceAdapter(),
            ]),
            storeFactory: new InMemorySignalStoreFactory(),
            patterns: new DiagnosticPatternRegistry([
                new CheckoutDegradationPattern(),
            ]),
            reportBuilder: new IncidentReportBuilder(),
        );
    }

    private function fullConfig(): ProvadoConfig
    {
        return $this->config([
            'new_relic' => $this->source('new_relic', enabled: true),
            'adobe_commerce' => $this->source('adobe_commerce', enabled: true),
        ]);
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

final readonly class ExplodingPattern implements DiagnosticPattern
{
    public function id(): string
    {
        return 'exploding';
    }

    public function name(): string
    {
        return 'Exploding pattern';
    }

    public function supports(CorrelationGroup $group): bool
    {
        return true;
    }

    public function evaluate(CorrelationGroup $group): PatternEvaluationResult
    {
        throw new RuntimeException('boom');
    }
}

final readonly class ExplodingSupportsPattern implements DiagnosticPattern
{
    public function id(): string
    {
        return 'exploding_supports';
    }

    public function name(): string
    {
        return 'Exploding supports pattern';
    }

    public function supports(CorrelationGroup $group): bool
    {
        throw new RuntimeException('boom in supports');
    }

    public function evaluate(CorrelationGroup $group): PatternEvaluationResult
    {
        return PatternEvaluationResult::none();
    }
}
