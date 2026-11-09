<?php

declare(strict_types=1);

namespace App\Billing\Contracts;

/** Published only through InvoiceVoided, so it is consumed wherever that event is. */
final readonly class Recipient
{
    public function __construct(
        public string $address,
    ) {}
}
