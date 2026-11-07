<?php

declare(strict_types=1);

namespace App\Billing\Application;

/** Expected outcomes decided outside Domain. Domain refusals keep their own identity. */
enum Denial
{
    case NotFound;
    case Forbidden;
    case Conflict;
}
