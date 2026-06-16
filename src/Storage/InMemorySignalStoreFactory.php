<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Storage;

final readonly class InMemorySignalStoreFactory implements SignalStoreFactory
{
    public function create(): SignalStore
    {
        return new InMemorySignalStore();
    }
}
