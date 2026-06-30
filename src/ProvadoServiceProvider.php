<?php

namespace Mquevedob\Provado;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Mquevedob\Provado\Config\ProvadoConfig;
use Mquevedob\Provado\Console\DiagnoseCommand;
use Mquevedob\Provado\Console\ShowSignalsCommand;
use Mquevedob\Provado\Http\HttpClient;
use Mquevedob\Provado\Http\LaravelHttpClient;
use Mquevedob\Provado\Incidents\IncidentReportBuilder;
use Mquevedob\Provado\Patterns\Catalog\CatalogFeedSyncFailurePattern;
use Mquevedob\Provado\Patterns\Checkout\CheckoutDegradationPattern;
use Mquevedob\Provado\Patterns\DiagnosticPatternRegistry;
use Mquevedob\Provado\Patterns\Operations\CronHealthPattern;
use Mquevedob\Provado\Patterns\Orders\OrderOperationsBacklogPattern;
use Mquevedob\Provado\Patterns\Payments\PaymentConfigRegressionPattern;
use Mquevedob\Provado\Pipeline\DiagnosticPipeline;
use Mquevedob\Provado\Pipeline\NoRetryPolicy;
use Mquevedob\Provado\Pipeline\NullPipelineObserver;
use Mquevedob\Provado\Pipeline\PipelineObserver;
use Mquevedob\Provado\Pipeline\PsrLoggerObserver;
use Mquevedob\Provado\Pipeline\RetryPolicy;
use Mquevedob\Provado\Sources\AdobeCommerce\AdobeCommerceAdapter;
use Mquevedob\Provado\Sources\AdobeCommerce\AdobeCommerceRestClient;
use Mquevedob\Provado\Sources\NewRelic\NerdGraphClient;
use Mquevedob\Provado\Sources\NewRelic\NewRelicAdapter;
use Mquevedob\Provado\Sources\SourceAdapterRegistry;
use Mquevedob\Provado\Storage\DatabaseSignalStoreFactory;
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
            $this->commands([DiagnoseCommand::class, ShowSignalsCommand::class]);
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

        $this->app->singleton(HttpClient::class, static function (Application $app): HttpClient {
            $config = $app['config'];

            return new LaravelHttpClient(
                $app->make(HttpFactory::class),
                self::positiveFloatOrNull($config->get('provado.http.timeout')),
                self::positiveFloatOrNull($config->get('provado.http.connect_timeout')),
            );
        });

        $this->app->singleton(SourceAdapterRegistry::class, static function (Application $app): SourceAdapterRegistry {
            return new SourceAdapterRegistry([
                new NewRelicAdapter(client: new NerdGraphClient($app->make(HttpClient::class))),
                new AdobeCommerceAdapter(client: new AdobeCommerceRestClient($app->make(HttpClient::class))),
            ]);
        });

        $this->app->singleton(SignalStoreFactory::class, static function (Application $app): SignalStoreFactory {
            $config = $app['config'];

            if ($config->get('provado.storage.driver', 'memory') === 'database') {
                $connection = $config->get('provado.storage.connection');

                return new DatabaseSignalStoreFactory(
                    $app->make('db'),
                    is_string($connection) && trim($connection) !== '' ? $connection : null,
                    (string) $config->get('provado.storage.table', 'provado_signals'),
                );
            }

            return new InMemorySignalStoreFactory();
        });

        $this->app->singleton(DiagnosticPatternRegistry::class, static function (): DiagnosticPatternRegistry {
            return new DiagnosticPatternRegistry([
                new CheckoutDegradationPattern(),
                new OrderOperationsBacklogPattern(),
                new PaymentConfigRegressionPattern(),
                new CatalogFeedSyncFailurePattern(),
                new CronHealthPattern(),
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

    /**
     * Coerce a config value into a positive float of seconds, or null when it
     * is absent or non-positive (meaning "no client-imposed timeout").
     */
    private static function positiveFloatOrNull(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $seconds = (float) $value;

        return $seconds > 0 ? $seconds : null;
    }
}
