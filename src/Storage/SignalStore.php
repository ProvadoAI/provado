<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Storage;

use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Core\SignalId;

interface SignalStore
{
    public function save(Signal $signal): void;

    /**
     * @param list<Signal> $signals
     */
    public function saveMany(array $signals): void;

    /**
     * @return list<Signal>
     */
    public function all(): array;

    /**
     * @return list<Signal>
     */
    public function query(SignalQuery $query): array;

    public function findById(SignalId $id): ?Signal;
}
