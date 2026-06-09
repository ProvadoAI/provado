<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Core;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DeploymentReference
{
    public function __construct(
        public string $id,
        public ?DateTimeImmutable $deployedAt = null,
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('DeploymentReference id cannot be empty.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->id === $other->id
            && $this->deployedAt == $other->deployedAt;
    }
}
