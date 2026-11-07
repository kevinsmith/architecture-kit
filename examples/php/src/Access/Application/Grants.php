<?php

declare(strict_types=1);

namespace App\Access\Application;

use App\Access\Domain\Grant;

interface Grants
{
    public function find(string $actor, string $permission, string $organization): ?Grant;
}
