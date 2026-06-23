<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Sources\NewRelic;

use Mquevedob\Provado\Config\SourceConfig;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Sources\SourceFetchResult;

/**
 * Credentialed New Relic client seam.
 *
 * The deferred NerdGraph client (see docs/roadmaps/v0.2.0.md) drops in here:
 * it implements this contract and the adapter selects it when New Relic
 * credentials are present, falling back to fixtures otherwise. Implementations
 * return the canonical {@see SourceFetchResult} — no vendor response shapes
 * cross this boundary.
 */
interface NewRelicClient
{
    public function fetch(SourceConfig $config, TimeWindow $window): SourceFetchResult;
}
