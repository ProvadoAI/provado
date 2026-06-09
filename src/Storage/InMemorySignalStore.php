<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Storage;

use InvalidArgumentException;
use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Core\SignalId;

final class InMemorySignalStore implements SignalStore
{
    /**
     * @var array<string, Signal>
     */
    private array $signalsById = [];

    public function save(Signal $signal): void
    {
        $this->signalsById[$signal->id->value] = $signal;
    }

    public function saveMany(array $signals): void
    {
        foreach ($signals as $signal) {
            if (! $signal instanceof Signal) {
                throw new InvalidArgumentException('SignalStore::saveMany expects only Signal instances.');
            }
        }

        foreach ($signals as $signal) {
            $this->save($signal);
        }
    }

    public function all(): array
    {
        return array_values($this->signalsById);
    }

    public function query(SignalQuery $query): array
    {
        $matches = [];

        foreach ($this->signalsById as $signal) {
            if ($query->source !== null && ! $signal->source->equals($query->source)) {
                continue;
            }

            if ($query->type !== null && ! $signal->type->equals($query->type)) {
                continue;
            }

            if ($query->severity !== null && ! $signal->severity->equals($query->severity)) {
                continue;
            }

            if ($query->entity !== null && ! $signal->hasEntity($query->entity)) {
                continue;
            }

            if ($query->window !== null && ! $query->window->contains($signal->timestamp)) {
                continue;
            }

            $matches[] = $signal;
        }

        return $matches;
    }

    public function findById(SignalId $id): ?Signal
    {
        return $this->signalsById[$id->value] ?? null;
    }
}
