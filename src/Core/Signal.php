<?php

declare(strict_types=1);

namespace Provado\Core;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Signal
{
    /**
     * @param list<EntityReference> $entityReferences
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public SignalId $id,
        public SignalSource $source,
        public SignalType $type,
        public DateTimeImmutable $timestamp,
        public SignalSeverity $severity,
        public array $entityReferences,
        public array $attributes,
        public RawPayloadReference $rawPayloadReference,
    ) {
        if ($entityReferences === []) {
            throw new InvalidArgumentException('Signal must reference at least one entity.');
        }

        foreach ($entityReferences as $entityReference) {
            if (! $entityReference instanceof EntityReference) {
                throw new InvalidArgumentException('Signal entity references must be EntityReference instances.');
            }
        }

        foreach (array_keys($attributes) as $attributeName) {
            if (! is_string($attributeName) || trim($attributeName) === '') {
                throw new InvalidArgumentException('Signal attribute names cannot be empty.');
            }
        }
    }

    public function equals(self $other): bool
    {
        return $this->id->equals($other->id);
    }

    public function hasEntity(EntityReference $entityReference): bool
    {
        foreach ($this->entityReferences as $candidate) {
            if ($candidate->equals($entityReference)) {
                return true;
            }
        }

        return false;
    }
}
