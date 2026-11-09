<?php

declare(strict_types=1);

namespace ArchitectureCheck;

/** Logical roles (R02). Module roles are namespace segments under their module. */
enum Role: string
{
    case Contracts = 'Contracts';
    case Domain = 'Domain';
    case Application = 'Application';
    case Presentation = 'Presentation';
    case Infrastructure = 'Infrastructure';
    case Shared = 'Shared Infrastructure';
    case Composition = 'composition';

    public const array MODULE_ROLES = [self::Contracts, self::Domain, self::Application, self::Presentation, self::Infrastructure];

    /** Domain and published contracts may not reach the clock or ambient state (R04, R05). */
    public function isPure(): bool
    {
        return $this === self::Domain || $this === self::Contracts;
    }
}
