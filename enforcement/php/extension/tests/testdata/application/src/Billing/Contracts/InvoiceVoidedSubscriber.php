<?php

declare(strict_types=1);

namespace App\Billing\Contracts;

interface InvoiceVoidedSubscriber
{
    public function deliver(InvoiceVoided $event): void;
}
