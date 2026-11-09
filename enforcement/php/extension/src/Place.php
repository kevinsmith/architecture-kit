<?php

declare(strict_types=1);

namespace ArchitectureCheck;

/** A classified location: its module, or the shared or composition namespace, and its role. */
final readonly class Place
{
    public function __construct(
        public string $module,
        public Role $role,
    ) {}
}
