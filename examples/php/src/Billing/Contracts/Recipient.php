<?php

declare(strict_types=1);

namespace App\Billing\Contracts;

final readonly class Recipient
{
    public function __construct(
        public string $address,
    ) {
        if ($address === '') {
            throw new MalformedEvent('recipient address is required');
        }
    }
}
