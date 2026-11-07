<?php

declare(strict_types=1);

namespace App\Billing\Domain;

/** Business reasons a void is refused. */
enum Refusal
{
    case AlreadyVoided;
    case Settled;
    case Expired;
    case InvalidState;
}
