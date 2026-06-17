<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Storage;

use Mquevedob\Provado\Core\EntityReference;
use Mquevedob\Provado\Core\Signal;
use Mquevedob\Provado\Core\SignalSeverity;
use Mquevedob\Provado\Core\SignalSource;
use Mquevedob\Provado\Core\SignalType;
use Mquevedob\Provado\Core\TimeWindow;

final readonly class SignalQuery
{
    private function __construct(
        public ?SignalSource $source = null,
        public ?SignalType $type = null,
        public ?SignalSeverity $severity = null,
        public ?EntityReference $entity = null,
        public ?TimeWindow $window = null,
    ) {
    }

    public static function all(): self
    {
        return new self();
    }

    public function withSource(SignalSource|string $source): self
    {
        return new self(
            source: is_string($source) ? new SignalSource($source) : $source,
            type: $this->type,
            severity: $this->severity,
            entity: $this->entity,
            window: $this->window,
        );
    }

    public function withType(SignalType|string $type): self
    {
        return new self(
            source: $this->source,
            type: is_string($type) ? new SignalType($type) : $type,
            severity: $this->severity,
            entity: $this->entity,
            window: $this->window,
        );
    }

    public function withSeverity(SignalSeverity|string $severity): self
    {
        return new self(
            source: $this->source,
            type: $this->type,
            severity: is_string($severity) ? new SignalSeverity($severity) : $severity,
            entity: $this->entity,
            window: $this->window,
        );
    }

    public function withEntity(EntityReference $entity): self
    {
        return new self(
            source: $this->source,
            type: $this->type,
            severity: $this->severity,
            entity: $entity,
            window: $this->window,
        );
    }

    public function within(TimeWindow $window): self
    {
        return new self(
            source: $this->source,
            type: $this->type,
            severity: $this->severity,
            entity: $this->entity,
            window: $window,
        );
    }

    /**
     * Whether a signal satisfies every filter set on this query. Shared by all
     * SignalStore implementations so they match signals identically.
     */
    public function matches(Signal $signal): bool
    {
        if ($this->source !== null && ! $signal->source->equals($this->source)) {
            return false;
        }

        if ($this->type !== null && ! $signal->type->equals($this->type)) {
            return false;
        }

        if ($this->severity !== null && ! $signal->severity->equals($this->severity)) {
            return false;
        }

        if ($this->entity !== null && ! $signal->hasEntity($this->entity)) {
            return false;
        }

        if ($this->window !== null && ! $this->window->contains($signal->timestamp)) {
            return false;
        }

        return true;
    }
}
