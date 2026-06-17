<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Console;

use DateInterval;
use DateTimeImmutable;
use Exception;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Mquevedob\Provado\Config\ProvadoConfig;
use Mquevedob\Provado\Config\SourceConfig;
use Mquevedob\Provado\Config\SourceCredentials;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Incidents\IncidentReportBuilder;
use Mquevedob\Provado\Incidents\IncidentReportRenderer;
use Mquevedob\Provado\Patterns\DiagnosticPatternRegistry;
use Mquevedob\Provado\Pipeline\DiagnosticPipeline;
use Mquevedob\Provado\Pipeline\RetryPolicy;
use Mquevedob\Provado\Sources\SourceAdapterRegistry;
use Mquevedob\Provado\Storage\SignalStoreFactory;

class DiagnoseCommand extends Command
{
    /**
     * The bundled fixtures are timestamped within this window, so --demo runs
     * over them produce a populated report out of the box.
     */
    private const DEMO_WINDOW_START = '2026-06-08T00:00:00+00:00';

    private const DEMO_WINDOW_END = '2026-06-09T00:00:00+00:00';

    protected $signature = 'provado:diagnose
        {--from= : Window start as an ISO 8601 datetime (defaults to 24h before --to)}
        {--to= : Window end as an ISO 8601 datetime (defaults to now)}
        {--demo : Run over the bundled fixtures with no credentials}';

    protected $description = 'Run the Provado diagnostic pipeline over the configured sources and render an incident report.';

    public function handle(
        SourceAdapterRegistry $adapters,
        SignalStoreFactory $storeFactory,
        DiagnosticPatternRegistry $patterns,
        IncidentReportBuilder $reportBuilder,
        RetryPolicy $retryPolicy,
        IncidentReportRenderer $renderer,
    ): int {
        $demo = (bool) $this->option('demo');

        try {
            $window = $this->resolveWindow($demo);
            $config = $demo ? $this->demoConfig() : $this->laravel->make(ProvadoConfig::class);
        } catch (Exception $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        // Reuse the container-wired pipeline graph, but observe the run through
        // the console so progress is visible as it happens.
        $pipeline = new DiagnosticPipeline(
            adapters: $adapters,
            storeFactory: $storeFactory,
            patterns: $patterns,
            reportBuilder: $reportBuilder,
            observer: new ConsolePipelineObserver($this->getOutput()),
            retryPolicy: $retryPolicy,
        );

        $result = $pipeline->run($config, $window);

        $this->newLine();

        if ($result->report !== null) {
            $this->line($renderer->renderText($result->report));
        } else {
            $this->info('No incident report was produced for the requested window.');
        }

        if ($result->hasErrors()) {
            $this->warn(sprintf(
                '%d source error(s) and %d stage error(s) occurred during the run.',
                count($result->errors),
                count($result->stageErrors),
            ));
        }

        return self::SUCCESS;
    }

    private function resolveWindow(bool $demo): TimeWindow
    {
        $from = $this->stringOption('from');
        $to = $this->stringOption('to');

        if ($demo && $from === null && $to === null) {
            return new TimeWindow(
                start: new DateTimeImmutable(self::DEMO_WINDOW_START),
                end: new DateTimeImmutable(self::DEMO_WINDOW_END),
            );
        }

        $end = $to !== null ? $this->parseDate('to', $to) : new DateTimeImmutable('now');
        $start = $from !== null ? $this->parseDate('from', $from) : $end->sub(new DateInterval('PT24H'));

        // TimeWindow rejects an end before its start with a clear message.
        return new TimeWindow($start, $end);
    }

    private function parseDate(string $option, string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            throw new InvalidArgumentException(sprintf(
                'Invalid --%s value "%s"; expected an ISO 8601 datetime.',
                $option,
                $value,
            ));
        }
    }

    private function demoConfig(): ProvadoConfig
    {
        // Built directly (not via fromArray) so the fixture sources can run
        // without credentials, mirroring the fixture-backed adapters' defaults.
        return new ProvadoConfig(true, [
            'new_relic' => new SourceConfig('new_relic', true, [], new SourceCredentials()),
            'adobe_commerce' => new SourceConfig('adobe_commerce', true, [], new SourceCredentials()),
        ]);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
