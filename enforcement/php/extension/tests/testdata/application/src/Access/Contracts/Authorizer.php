<?php

declare(strict_types=1);

namespace App\Access\Contracts;

interface Authorizer
{
    public function can(string $actor, string $organization): bool;
}
