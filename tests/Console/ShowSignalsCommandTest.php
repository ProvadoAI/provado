<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests\Console;

use Illuminate\Contracts\Foundation\Application;
use Mquevedob\Provado\ProvadoServiceProvider;
use Orchestra\Testbench\TestCase;

class ShowSignalsCommandTest extends TestCase
{
    /**
     * @param Application $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ProvadoServiceProvider::class];
    }

    public function test_demo_run_shows_signals_and_metric_values_per_source(): void
    {
        $this->artisan('provado:signals', ['--demo' => true])
            ->expectsOutputToContain('new_relic')
            ->expectsOutputToContain('duration_ms=2450')
            ->expectsOutputToContain('adobe_commerce')
            ->expectsOutputToContain('failure_rate=0.076')
            ->expectsOutputToContain('Total: 7 signal(s) across 2 source(s).')
            ->assertExitCode(0);
    }

    public function test_reports_when_no_sources_are_enabled(): void
    {
        // Default config ships both sources disabled.
        $this->artisan('provado:signals')
            ->expectsOutputToContain('No enabled sources are configured')
            ->assertExitCode(0);
    }

    public function test_demo_run_outside_the_fixture_window_shows_no_signals(): void
    {
        $this->artisan('provado:signals', [
            '--demo' => true,
            '--from' => '2020-01-01T00:00:00+00:00',
            '--to' => '2020-01-01T01:00:00+00:00',
        ])
            ->expectsOutputToContain('Total: 0 signal(s)')
            ->assertExitCode(0);
    }

    public function test_invalid_window_value_fails(): void
    {
        // No --demo: --window is only consulted when the window isn't the fixed
        // demo window, so this exercises the lookback parser.
        $this->artisan('provado:signals', ['--window' => 'soon'])
            ->assertExitCode(1);
    }

    public function test_invalid_datetime_fails(): void
    {
        $this->artisan('provado:signals', ['--from' => 'not-a-datetime'])
            ->assertExitCode(1);
    }
}
