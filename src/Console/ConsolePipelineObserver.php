<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Console;

use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Pipeline\PipelineDiagnostics;
use Mquevedob\Provado\Pipeline\PipelineError;
use Mquevedob\Provado\Pipeline\PipelineObserver;
use Mquevedob\Provado\Pipeline\SourceFetchSummary;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints pipeline progress to the console as a run unfolds. Only secret-safe
 * summary data is written; signal payloads are never emitted.
 */
final readonly class ConsolePipelineObserver implements PipelineObserver
{
    public function __construct(private OutputInterface $output)
    {
    }

    public function runStarted(TimeWindow $window): void
    {
        $this->output->writeln(sprintf(
            '<info>Running Provado diagnostics</info> for window %s -> %s',
            $window->start->format(DATE_ATOM),
            $window->end->format(DATE_ATOM),
        ));
    }

    public function sourceFetched(SourceFetchSummary $summary): void
    {
        $this->output->writeln(sprintf(
            '  - %s: %d signal(s), %d error(s)',
            $summary->sourceName,
            $summary->signalCount,
            $summary->errorCount,
        ));
    }

    public function sourceRetried(string $sourceName, int $attempt): void
    {
        $this->output->writeln(sprintf('  - retrying %s (attempt %d)', $sourceName, $attempt));
    }

    public function correlationCompleted(int $groupCount): void
    {
        $this->output->writeln(sprintf('  Correlated signals into %d group(s).', $groupCount));
    }

    public function patternEvaluated(string $patternId, int $findingCount): void
    {
        $this->output->writeln(sprintf('  Pattern "%s" produced %d finding(s).', $patternId, $findingCount));
    }

    public function stageFailed(PipelineError $error): void
    {
        $this->output->writeln(sprintf('  <error>! %s stage failed: %s</error>', $error->stage, $error->message));
    }

    public function runCompleted(PipelineDiagnostics $diagnostics): void
    {
        $this->output->writeln('<info>Run complete.</info>');
        $this->output->writeln(sprintf(
            '  %d signal(s), %d group(s), %d pattern(s) matched, %d finding(s), report %s.',
            $diagnostics->signalCount,
            $diagnostics->correlationGroupCount,
            $diagnostics->patternsMatched,
            $diagnostics->findingCount,
            $diagnostics->reportProduced ? 'produced' : 'not produced',
        ));
    }
}
