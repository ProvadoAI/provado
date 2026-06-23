<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Correlation;

use InvalidArgumentException;
use Mquevedob\Provado\Core\SignalSeverity;
use Mquevedob\Provado\Core\SignalSource;
use Mquevedob\Provado\Core\SignalType;
use Mquevedob\Provado\Core\TimeWindow;

final readonly class CorrelationCriteria
{
    private function __construct(
        public ?TimeWindow $window = null,
        public ?SignalSource $source = null,
        public ?SignalType $type = null,
        public ?SignalSeverity $severity = null,
        public ?int $timeProximitySeconds = null,
    ) {
        if ($timeProximitySeconds !== null && $timeProximitySeconds <= 0) {
            throw new InvalidArgumentException('Correlation time proximity must be greater than zero seconds.');
        }
    }

    public static function all(): self
    {
        return new self();
    }

    public function within(TimeWindow $window): self
    {
        return new self(
            window: $window,
            source: $this->source,
            type: $this->type,
            severity: $this->severity,
            timeProximitySeconds: $this->timeProximitySeconds,
        );
    }

    public function withSource(SignalSource|string $source): self
    {
        return new self(
            window: $this->window,
            source: is_string($source) ? new SignalSource($source) : $source,
            type: $this->type,
            severity: $this->severity,
            timeProximitySeconds: $this->timeProximitySeconds,
        );
    }

    public function withType(SignalType|string $type): self
    {
        return new self(
            window: $this->window,
            source: $this->source,
            type: is_string($type) ? new SignalType($type) : $type,
            severity: $this->severity,
            timeProximitySeconds: $this->timeProximitySeconds,
        );
    }

    public function withSeverity(SignalSeverity|string $severity): self
    {
        return new self(
            window: $this->window,
            source: $this->source,
            type: $this->type,
            severity: is_string($severity) ? new SignalSeverity($severity) : $severity,
            timeProximitySeconds: $this->timeProximitySeconds,
        );
    }

    /**
     * Also join signals whose timestamps fall within the given number of
     * seconds of each other, in addition to the default shared-entity join.
     * Disabled by default (null), preserving entity-only correlation.
     */
    public function withTimeProximity(int $seconds): self
    {
        return new self(
            window: $this->window,
            source: $this->source,
            type: $this->type,
            severity: $this->severity,
            timeProximitySeconds: $seconds,
        );
    }
}
