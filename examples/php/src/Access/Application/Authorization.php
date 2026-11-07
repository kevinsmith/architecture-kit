<?php

declare(strict_types=1);

namespace App\Access\Application;

use App\Access\Contracts\Authorizer;
use Override;

final readonly class Authorization implements Authorizer
{
    public function __construct(
        private Grants $grants,
    ) {}

    #[Override]
    public function can(string $actor, string $permission, string $organization): bool
    {
        $grant = $this->grants->find($actor, $permission, $organization);

        return $grant !== null && $grant->allows($actor, $permission, $organization);
    }
}
