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
use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Sources\SourceAdapterRegistry;

/**
 * Fetch each configured source and print the canonical signals (metrics) it
 * returns — a read-only inspection command. Unlike provado:diagnose it runs no
 * correlation or pattern evaluation; it just shows what the adapters read, so an
 * operator can eyeball live metric values per source.
 */
class ShowSignalsCommand extends Command
{
    /** The bundled fixtures are timestamped within this window. */
    private const DEMO_WINDOW_START = '2026-06-08T00:00:00+00:00';

    private const DEMO_WINDOW_END = '2026-06-09T00:00:00+00:00';

    protected $signature = 'provado:signals
        {--from= : Window start as an ISO 8601 datetime (defaults to --to minus the window)}
        {--to= : Window end as an ISO 8601 datetime (defaults to now)}
        {--window=24h : Lookback window when --from is omitted (e.g. 60m, 24h, 7d)}
        {--demo : Read the bundled fixtures with no credentials}';

    protected $description = 'Fetch the configured sources and display the canonical signals (metrics) they return.';

    public function handle(SourceAdapterRegistry $adapters): int
    {
        $demo = (bool) $this->option('demo');

        try {
            $window = $this->resolveWindow($demo);
            $config = $demo ? $this->demoConfig() : $this->laravel->make(ProvadoConfig::class);
        } catch (Exception $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $enabledAdapters = $adapters->enabledAdaptersFor($config);

        if ($enabledAdapters === []) {
            $this->warn('No enabled sources are configured. Set credentials (or pass --demo).');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Window: %s → %s',
            $window->start->format(DATE_ATOM),
            $window->end->format(DATE_ATOM),
        ));

        $totalSignals = 0;

        foreach ($enabledAdapters as $name => $adapter) {
            $result = $adapter->fetch($config->source($name), $window);
            $signals = $result->signals();
            $totalSignals += count($signals);

            $this->newLine();
            $this->line(sprintf('<info>%s</info> — %d signal(s)', $name, count($signals)));

            if ($signals !== []) {
                $this->table(
                    ['Type', 'Severity', 'Entities', 'Attributes', 'When (UTC)'],
                    array_map([$this, 'signalRow'], $signals),
                );
            }

            foreach ($result->errors() as $error) {
                $this->warn(sprintf(
                    '  ! [%s] %s (%s): %s',
                    $error->code ?? 'error',
                    $error->retryable === true ? 'retryable' : 'non-retryable',
                    $error->sourceName,
                    $error->message,
                ));
            }
        }

        $this->newLine();
        $this->line(sprintf('Total: %d signal(s) across %d source(s).', $totalSignals, count($enabledAdapters)));

        return self::SUCCESS;
    }

    /**
     * @return array{string, string, string, string, string}
     */
    private function signalRow(Signal $signal): array
    {
        $entities = implode(', ', array_map(
            static fn ($entity): string => $entity->type.':'.$entity->id,
            $signal->entityReferences,
        ));

        $attributes = [];
        foreach ($signal->attributes as $name => $value) {
            $attributes[] = $name.'='.$value;
        }

        return [
            $signal->type->value,
            $signal->severity->value,
            $entities,
            implode(' ', $attributes),
            $signal->timestamp->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];
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
        $start = $from !== null ? $this->parseDate('from', $from) : $end->sub($this->lookback());

        // TimeWindow rejects an end before its start with a clear message.
        return new TimeWindow($start, $end);
    }

    /**
     * Parse the --window lookback (e.g. 90m, 24h, 7d) into a DateInterval.
     */
    private function lookback(): DateInterval
    {
        $value = $this->stringOption('window') ?? '24h';

        if (preg_match('/^(\d+)([mhd])$/', strtolower(trim($value)), $matches) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid --window value "%s"; use e.g. 60m, 24h, 7d.', $value));
        }

        $amount = (int) $matches[1];

        return match ($matches[2]) {
            'm' => new DateInterval('PT'.$amount.'M'),
            'h' => new DateInterval('PT'.$amount.'H'),
            'd' => new DateInterval('P'.$amount.'D'),
        };
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
