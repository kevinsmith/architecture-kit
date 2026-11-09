<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Contracts\InvoiceVoided;
use App\Billing\Domain\Invoice;

interface InvoiceTransaction
{
    public function save(Invoice $invoice): void;

    public function addEvent(InvoiceVoided $event): void;
}
