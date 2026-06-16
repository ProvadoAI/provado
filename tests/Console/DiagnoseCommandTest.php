<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests\Console;

use Illuminate\Contracts\Foundation\Application;
use Mquevedob\Provado\ProvadoServiceProvider;
use Orchestra\Testbench\TestCase;

class DiagnoseCommandTest extends TestCase
{
    /**
     * @param Application $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ProvadoServiceProvider::class];
    }

    public function test_demo_run_renders_a_populated_incident_report(): void
    {
        $this->artisan('provado:diagnose', ['--demo' => true])
            ->expectsOutputToContain('Running Provado diagnostics')
            ->expectsOutputToContain('new_relic: 3 signal(s)')
            ->expectsOutputToContain('adobe_commerce: 4 signal(s)')
            ->expectsOutputToContain('Incident Report')
            ->expectsOutputToContain('Severity: critical')
            ->assertExitCode(0);
    }

    public function test_run_without_enabled_sources_reports_no_incident(): void
    {
        // Default config ships both sources disabled, so the pipeline collects
        // no signals and produces no report.
        $this->artisan('provado:diagnose')
            ->expectsOutputToContain('No incident report was produced')
            ->assertExitCode(0);
    }

    public function test_demo_run_outside_the_fixture_window_reports_no_incident(): void
    {
        $this->artisan('provado:diagnose', [
            '--demo' => true,
            '--from' => '2020-01-01T00:00:00+00:00',
            '--to' => '2020-01-01T01:00:00+00:00',
        ])
            ->expectsOutputToContain('No incident report was produced')
            ->assertExitCode(0);
    }

    public function test_window_with_end_before_start_fails(): void
    {
        $this->artisan('provado:diagnose', [
            '--from' => '2026-01-02T00:00:00+00:00',
            '--to' => '2026-01-01T00:00:00+00:00',
        ])->assertExitCode(1);
    }

    public function test_invalid_datetime_fails(): void
    {
        $this->artisan('provado:diagnose', ['--from' => 'not-a-datetime'])
            ->assertExitCode(1);
    }
}
