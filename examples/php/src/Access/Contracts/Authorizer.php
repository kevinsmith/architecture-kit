<?php

declare(strict_types=1);

namespace App\Access\Contracts;

/**
 * Access's published authorization contract. Callers supply the actor, the
 * permission, and the resource's actual scope; Access does not fetch resources.
 */
interface Authorizer
{
    public function can(string $actor, string $permission, string $organization): bool;
}
