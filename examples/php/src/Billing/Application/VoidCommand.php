<?php

declare(strict_types=1);

namespace App\Billing\Application;

final readonly class VoidCommand
{
    public function __construct(
        public string $invoiceId,
        public string $organizationId,
    ) {}
}
