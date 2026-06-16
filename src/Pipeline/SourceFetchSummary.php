<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Pipeline;

final readonly class SourceFetchSummary
{
    public function __construct(
        public string $sourceName,
        public int $signalCount,
        public int $errorCount,
        public int $retryableErrorCount,
    ) {
    }
}
