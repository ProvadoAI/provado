<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Core;

use InvalidArgumentException;

final readonly class EntityReference
{
    public function __construct(
        public string $type,
        public string $id,
    ) {
        if (trim($type) === '') {
            throw new InvalidArgumentException('EntityReference type cannot be empty.');
        }

        if (trim($id) === '') {
            throw new InvalidArgumentException('EntityReference id cannot be empty.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type
            && $this->id === $other->id;
    }
}
