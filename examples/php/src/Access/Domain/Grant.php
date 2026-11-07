<?php

declare(strict_types=1);

namespace App\Access\Domain;

final readonly class Grant
{
    public function __construct(
        public string $actor,
        public string $permission,
        public string $organization,
    ) {}

    public function allows(string $actor, string $permission, string $organization): bool
    {
        return $actor !== ''
            && $this->actor === $actor
            && $this->permission === $permission
            && $this->organization === $organization;
    }
}
