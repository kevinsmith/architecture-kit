<?php

declare(strict_types=1);

namespace App\Billing\Domain;

enum Status: string
{
    case Open = 'open';
    case Settled = 'settled';
    case Voided = 'voided';
}
