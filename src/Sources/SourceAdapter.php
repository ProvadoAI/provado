<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Sources;

use Mquevedob\Provado\Config\SourceConfig;
use Mquevedob\Provado\Core\TimeWindow;

interface SourceAdapter
{
    public function name(): string;

    public function supports(SourceConfig $config): bool;

    public function fetch(SourceConfig $config, TimeWindow $window): SourceFetchResult;
}
