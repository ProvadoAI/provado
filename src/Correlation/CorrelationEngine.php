<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Correlation;

use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Core\TimeWindow;
use Mquevedob\Provado\Storage\SignalQuery;
use Mquevedob\Provado\Storage\SignalStore;

final readonly class CorrelationEngine
{
    public function __construct(private SignalStore $signals)
    {
    }

    /**
     * @return list<CorrelationGroup>
     */
    public function correlate(TimeWindow $window, ?CorrelationCriteria $criteria = null): array
    {
        $criteria ??= CorrelationCriteria::all();
        $query = SignalQuery::all()
            ->within($criteria->window ?? $window);

        if ($criteria->source !== null) {
            $query = $query->withSource($criteria->source);
        }

        if ($criteria->type !== null) {
            $query = $query->withType($criteria->type);
        }

        if ($criteria->severity !== null) {
            $query = $query->withSeverity($criteria->severity);
        }

        return $this->groupsFor($this->signals->query($query));
    }

    /**
     * @param list<Signal> $signals
     * @return list<CorrelationGroup>
     */
    private function groupsFor(array $signals): array
    {
        $visitedSignalIds = [];
        $groups = [];

        foreach ($signals as $signal) {
            if (isset($visitedSignalIds[$signal->id->value])) {
                continue;
            }

            $groupSignals = $this->connectedSignals($signal, $signals);

            foreach ($groupSignals as $groupSignal) {
                $visitedSignalIds[$groupSignal->id->value] = true;
            }

            if (count($groupSignals) > 1) {
                $groups[] = new CorrelationGroup($groupSignals);
            }
        }

        return $groups;
    }

    /**
     * @param list<Signal> $signals
     * @return list<Signal>
     */
    private function connectedSignals(Signal $root, array $signals): array
    {
        $connectedSignals = [];
        $visitedSignalIds = [];
        $queue = [$root];

        while ($queue !== []) {
            $signal = array_shift($queue);

            if (isset($visitedSignalIds[$signal->id->value])) {
                continue;
            }

            $visitedSignalIds[$signal->id->value] = true;
            $connectedSignals[] = $signal;

            foreach ($signals as $candidate) {
                if (isset($visitedSignalIds[$candidate->id->value])) {
                    continue;
                }

                if ($this->signalsShareEntity($signal, $candidate)) {
                    $queue[] = $candidate;
                }
            }
        }

        return $connectedSignals;
    }

    private function signalsShareEntity(Signal $first, Signal $second): bool
    {
        foreach ($first->entityReferences as $entityReference) {
            if ($second->hasEntity($entityReference)) {
                return true;
            }
        }

        return false;
    }
}
