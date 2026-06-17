<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Storage;

use Illuminate\Database\ConnectionResolverInterface;

/**
 * Produces database-backed signal stores, each scoped to a fresh run id so a
 * run stays isolated (matching the in-memory factory's per-run semantics)
 * while its signals persist in the configured connection.
 */
final readonly class DatabaseSignalStoreFactory implements SignalStoreFactory
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
        private string $table = 'provado_signals',
    ) {
    }

    public function create(): SignalStore
    {
        return new DatabaseSignalStore(
            $this->connections->connection($this->connection),
            bin2hex(random_bytes(16)),
            $this->table,
        );
    }
}
