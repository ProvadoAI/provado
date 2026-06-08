<?php

declare(strict_types=1);

namespace Provado\Core;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class TimeWindow
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {
        if ($end < $start) {
            throw new InvalidArgumentException('TimeWindow end cannot be before start.');
        }
    }

    public function contains(DateTimeImmutable $timestamp): bool
    {
        return $timestamp >= $this->start && $timestamp <= $this->end;
    }

    public function equals(self $other): bool
    {
        return $this->start == $other->start
            && $this->end == $other->end;
    }
}
