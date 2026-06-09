<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Correlation;

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
    ) {
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
        );
    }

    public function withSource(SignalSource|string $source): self
    {
        return new self(
            window: $this->window,
            source: is_string($source) ? new SignalSource($source) : $source,
            type: $this->type,
            severity: $this->severity,
        );
    }

    public function withType(SignalType|string $type): self
    {
        return new self(
            window: $this->window,
            source: $this->source,
            type: is_string($type) ? new SignalType($type) : $type,
            severity: $this->severity,
        );
    }

    public function withSeverity(SignalSeverity|string $severity): self
    {
        return new self(
            window: $this->window,
            source: $this->source,
            type: $this->type,
            severity: is_string($severity) ? new SignalSeverity($severity) : $severity,
        );
    }
}
