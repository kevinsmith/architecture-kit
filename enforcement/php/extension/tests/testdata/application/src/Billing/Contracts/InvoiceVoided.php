<?php

declare(strict_types=1);

namespace App\Billing\Contracts;

final readonly class InvoiceVoided
{
    /**
     * @param list<Recipient> $recipients
     */
    public function __construct(
        public string $invoiceId,
        public array $recipients,
    ) {}
}
