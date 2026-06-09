<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Correlation;

use DateTimeImmutable;
use InvalidArgumentException;
use Mquevedob\Provado\Core\EntityReference;
use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Core\SignalSeverity;
use Mquevedob\Provado\Core\SignalSource;
use Mquevedob\Provado\Core\SignalType;

final readonly class CorrelationGroup
{
    public CorrelationId $id;

    /**
     * @param list<Signal> $signals
     */
    public function __construct(public array $signals)
    {
        if ($signals === []) {
            throw new InvalidArgumentException('CorrelationGroup requires at least one signal.');
        }

        foreach ($signals as $signal) {
            if (! $signal instanceof Signal) {
                throw new InvalidArgumentException('CorrelationGroup requires Signal instances.');
            }
        }

        $this->id = CorrelationId::fromSignals($signals);
    }

    /**
     * @return list<EntityReference>
     */
    public function sharedEntities(): array
    {
        $entitiesByKey = [];
        $signalIdsByEntityKey = [];

        foreach ($this->signals as $signal) {
            foreach ($signal->entityReferences as $entityReference) {
                $key = $this->entityKey($entityReference);
                $entitiesByKey[$key] = $entityReference;
                $signalIdsByEntityKey[$key][$signal->id->value] = true;
            }
        }

        $sharedEntities = [];

        foreach ($signalIdsByEntityKey as $key => $signalIds) {
            if (count($signalIds) > 1) {
                $sharedEntities[] = $entitiesByKey[$key];
            }
        }

        return $sharedEntities;
    }

    /**
     * @return list<SignalSource>
     */
    public function involvedSources(): array
    {
        $sources = [];

        foreach ($this->signals as $signal) {
            $sources[$signal->source->value] = $signal->source;
        }

        return array_values($sources);
    }

    /**
     * @return list<SignalType>
     */
    public function involvedTypes(): array
    {
        $types = [];

        foreach ($this->signals as $signal) {
            $types[$signal->type->value] = $signal->type;
        }

        return array_values($types);
    }

    public function highestSeverity(): SignalSeverity
    {
        $highest = $this->signals[0]->severity;

        foreach ($this->signals as $signal) {
            if ($this->severityRank($signal->severity) > $this->severityRank($highest)) {
                $highest = $signal->severity;
            }
        }

        return $highest;
    }

    public function startsAt(): DateTimeImmutable
    {
        $start = $this->signals[0]->timestamp;

        foreach ($this->signals as $signal) {
            if ($signal->timestamp < $start) {
                $start = $signal->timestamp;
            }
        }

        return $start;
    }

    public function endsAt(): DateTimeImmutable
    {
        $end = $this->signals[0]->timestamp;

        foreach ($this->signals as $signal) {
            if ($signal->timestamp > $end) {
                $end = $signal->timestamp;
            }
        }

        return $end;
    }

    private function entityKey(EntityReference $entityReference): string
    {
        return $entityReference->type."\0".$entityReference->id;
    }

    private function severityRank(SignalSeverity $severity): int
    {
        return match ($severity->value) {
            'info' => 10,
            'warning' => 20,
            'error' => 30,
            'critical' => 40,
        };
    }
}
