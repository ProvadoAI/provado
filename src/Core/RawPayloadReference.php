<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Core;

use InvalidArgumentException;

final readonly class RawPayloadReference
{
    public function __construct(
        public string $id,
        public ?string $location = null,
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('RawPayloadReference id cannot be empty.');
        }

        if ($location !== null && trim($location) === '') {
            throw new InvalidArgumentException('RawPayloadReference location cannot be empty when provided.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->id === $other->id
            && $this->location === $other->location;
    }
}
