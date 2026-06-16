<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Storage;

/**
 * Produces a fresh signal store, letting the pipeline work in an isolated
 * store per run instead of accumulating signals across runs.
 */
interface SignalStoreFactory
{
    public function create(): SignalStore;
}
