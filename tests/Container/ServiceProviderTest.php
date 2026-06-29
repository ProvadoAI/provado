<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests\Container;

use DateTimeImmutable;
use Illuminate\Contracts\Foundation\Application;
use Mquevedob\Provado\Config\ProvadoConfig;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Http\FakeHttpClient;
use Mquevedob\Provado\Http\HttpClient;
use Mquevedob\Provado\Http\HttpResponse;
use Mquevedob\Provado\Http\LaravelHttpClient;
use Mquevedob\Provado\Incidents\IncidentReportBuilder;
use Mquevedob\Provado\Patterns\DiagnosticPatternRegistry;
use Mquevedob\Provado\Pipeline\DiagnosticPipeline;
use Mquevedob\Provado\Pipeline\NoRetryPolicy;
use Mquevedob\Provado\Pipeline\NullPipelineObserver;
use Mquevedob\Provado\Pipeline\PipelineObserver;
use Mquevedob\Provado\Pipeline\PsrLoggerObserver;
use Mquevedob\Provado\Pipeline\RetryPolicy;
use Mquevedob\Provado\ProvadoServiceProvider;
use Mquevedob\Provado\Sources\SourceAdapterRegistry;
use Mquevedob\Provado\Storage\InMemorySignalStoreFactory;
use Mquevedob\Provado\Storage\SignalStoreFactory;
use Orchestra\Testbench\TestCase;

class ServiceProviderTest extends TestCase
{
    /**
     * @param Application $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ProvadoServiceProvider::class];
    }

    public function test_container_resolves_the_full_pipeline_graph(): void
    {
        $this->assertInstanceOf(DiagnosticPipeline::class, $this->app->make(DiagnosticPipeline::class));
        $this->assertInstanceOf(SourceAdapterRegistry::class, $this->app->make(SourceAdapterRegistry::class));
        $this->assertInstanceOf(DiagnosticPatternRegistry::class, $this->app->make(DiagnosticPatternRegistry::class));
        $this->assertInstanceOf(IncidentReportBuilder::class, $this->app->make(IncidentReportBuilder::class));
    }

    public function test_pipeline_and_registries_are_bound_as_singletons(): void
    {
        $this->assertSame($this->app->make(DiagnosticPipeline::class), $this->app->make(DiagnosticPipeline::class));
        $this->assertSame($this->app->make(SourceAdapterRegistry::class), $this->app->make(SourceAdapterRegistry::class));
        $this->assertSame($this->app->make(DiagnosticPatternRegistry::class), $this->app->make(DiagnosticPatternRegistry::class));
        $this->assertSame($this->app->make(ProvadoConfig::class), $this->app->make(ProvadoConfig::class));
    }

    public function test_signal_store_factory_resolves_to_in_memory_default(): void
    {
        $this->assertInstanceOf(InMemorySignalStoreFactory::class, $this->app->make(SignalStoreFactory::class));
    }

    public function test_http_client_resolves_to_the_laravel_implementation_as_a_singleton(): void
    {
        $this->assertInstanceOf(LaravelHttpClient::class, $this->app->make(HttpClient::class));
        $this->assertSame($this->app->make(HttpClient::class), $this->app->make(HttpClient::class));
    }

    public function test_retry_policy_defaults_to_no_retry(): void
    {
        $this->assertInstanceOf(NoRetryPolicy::class, $this->app->make(RetryPolicy::class));
    }

    public function test_observer_defaults_to_null_observer(): void
    {
        $this->assertInstanceOf(NullPipelineObserver::class, $this->app->make(PipelineObserver::class));
    }

    public function test_observer_uses_psr_logger_when_logging_is_enabled(): void
    {
        $this->app['config']->set('provado.observability.logging', true);

        $this->assertInstanceOf(PsrLoggerObserver::class, $this->app->make(PipelineObserver::class));
    }

    public function test_provado_config_is_built_from_config_repository(): void
    {
        /** @var ProvadoConfig $config */
        $config = $this->app->make(ProvadoConfig::class);

        $this->assertTrue($config->enabled);
        $this->assertSame('new_relic', $config->source('new_relic')->name);
        $this->assertSame('adobe_commerce', $config->source('adobe_commerce')->name);
        // Sources are disabled by default until credentials are configured.
        $this->assertFalse($config->source('new_relic')->enabled);
        $this->assertFalse($config->source('adobe_commerce')->enabled);
    }

    public function test_resolved_pipeline_runs_end_to_end_without_live_calls(): void
    {
        // With credentials present, both sources now resolve to their live clients
        // (NerdGraph + Adobe Commerce REST); bind a fake transport so the
        // container-resolved pipeline makes no real outbound call. (The
        // fixture-data → critical-report scenario is covered by PipelineTest, which
        // builds adapters without a live client.) Responses are consumed in fetch
        // order: New Relic first, then Adobe Commerce.
        $fakeHttp = (new FakeHttpClient())
            ->respondWith(new HttpResponse(200, (string) json_encode([
                'data' => ['actor' => ['account' => ['nrql' => ['results' => []]]]],
            ])))
            ->respondWith(new HttpResponse(200, (string) json_encode([
                'items' => [
                    ['entity_id' => 1, 'store_id' => 1, 'grand_total' => 99.5, 'status' => 'complete'],
                ],
                'total_count' => 1,
            ])));
        $this->app->instance(HttpClient::class, $fakeHttp);

        $this->enableSources();

        $pipeline = $this->app->make(DiagnosticPipeline::class);
        $config = $this->app->make(ProvadoConfig::class);

        $result = $pipeline->run($config, $this->fixtureWindow());

        // New Relic: empty NerdGraph result → 0 signals. Adobe Commerce: one order
        // → one aggregate order_activity signal. Two outbound calls, both faked.
        $this->assertFalse($result->hasErrors());
        $this->assertCount(2, $fakeHttp->sentRequests());
        $this->assertSame(1, $result->diagnostics->signalCount);

        $signalsBySource = [];
        foreach ($result->diagnostics->sources as $summary) {
            $signalsBySource[$summary->sourceName] = $summary->signalCount;
            $this->assertSame(0, $summary->errorCount);
        }

        $this->assertSame(0, $signalsBySource['new_relic']);
        $this->assertSame(1, $signalsBySource['adobe_commerce']);
    }

    private function enableSources(): void
    {
        $this->app['config']->set('provado.sources.new_relic', [
            'enabled' => true,
            'options' => ['account_id' => '000000'],
            'credentials' => ['api_key' => 'test-key'],
        ]);
        $this->app['config']->set('provado.sources.adobe_commerce', [
            'enabled' => true,
            'options' => ['base_url' => 'https://commerce.example.test'],
            'credentials' => ['access_token' => 'test-token'],
        ]);
    }

    private function fixtureWindow(): TimeWindow
    {
        return new TimeWindow(
            start: new DateTimeImmutable('2026-06-08T12:00:00+00:00'),
            end: new DateTimeImmutable('2026-06-08T12:30:00+00:00'),
        );
    }
}
