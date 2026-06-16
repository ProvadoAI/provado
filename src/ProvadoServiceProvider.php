<?php

namespace Mquevedob\Provado;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Mquevedob\Provado\Config\ProvadoConfig;
use Mquevedob\Provado\Console\DiagnoseCommand;
use Mquevedob\Provado\Incidents\IncidentReportBuilder;
use Mquevedob\Provado\Patterns\Checkout\CheckoutDegradationPattern;
use Mquevedob\Provado\Patterns\DiagnosticPatternRegistry;
use Mquevedob\Provado\Pipeline\DiagnosticPipeline;
use Mquevedob\Provado\Pipeline\NoRetryPolicy;
use Mquevedob\Provado\Pipeline\NullPipelineObserver;
use Mquevedob\Provado\Pipeline\PipelineObserver;
use Mquevedob\Provado\Pipeline\PsrLoggerObserver;
use Mquevedob\Provado\Pipeline\RetryPolicy;
use Mquevedob\Provado\Sources\AdobeCommerce\AdobeCommerceAdapter;
use Mquevedob\Provado\Sources\NewRelic\NewRelicAdapter;
use Mquevedob\Provado\Sources\SourceAdapterRegistry;
use Mquevedob\Provado\Storage\InMemorySignalStoreFactory;
use Mquevedob\Provado\Storage\SignalStoreFactory;
use Psr\Log\LoggerInterface;

class ProvadoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/provado.php', 'provado');

        $this->registerPipeline();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/provado.php' => config_path('provado.php'),
        ], 'provado-config');

        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([DiagnoseCommand::class]);
        }
    }

    /**
     * Bind the full diagnostic pipeline graph into the container.
     *
     * Everything is reused from the v0.1.0 seams: this only wires the existing
     * pieces together so a Laravel app can resolve a ready-to-run pipeline.
     * Fixture-backed adapters remain the default registered sources.
     */
    private function registerPipeline(): void
    {
        $this->app->singleton(ProvadoConfig::class, static function (Application $app): ProvadoConfig {
            return ProvadoConfig::fromArray((array) $app['config']->get('provado', []));
        });

        $this->app->singleton(SourceAdapterRegistry::class, static function (): SourceAdapterRegistry {
            return new SourceAdapterRegistry([
                new NewRelicAdapter(),
                new AdobeCommerceAdapter(),
            ]);
        });

        $this->app->singleton(SignalStoreFactory::class, InMemorySignalStoreFactory::class);

        $this->app->singleton(DiagnosticPatternRegistry::class, static function (): DiagnosticPatternRegistry {
            return new DiagnosticPatternRegistry([
                new CheckoutDegradationPattern(),
            ]);
        });

        $this->app->singleton(IncidentReportBuilder::class);

        $this->app->singleton(RetryPolicy::class, NoRetryPolicy::class);

        $this->app->singleton(PipelineObserver::class, static function (Application $app): PipelineObserver {
            $loggingEnabled = (bool) $app['config']->get('provado.observability.logging', false);

            if ($loggingEnabled) {
                return new PsrLoggerObserver($app->make(LoggerInterface::class));
            }

            return new NullPipelineObserver();
        });

        $this->app->singleton(DiagnosticPipeline::class, static function (Application $app): DiagnosticPipeline {
            return new DiagnosticPipeline(
                adapters: $app->make(SourceAdapterRegistry::class),
                storeFactory: $app->make(SignalStoreFactory::class),
                patterns: $app->make(DiagnosticPatternRegistry::class),
                reportBuilder: $app->make(IncidentReportBuilder::class),
                observer: $app->make(PipelineObserver::class),
                retryPolicy: $app->make(RetryPolicy::class),
            );
        });
    }
}
