<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Contracts\InvoiceVoided;
use App\Billing\Domain\Invoice;

/** Operations bound to one open transaction. Valid only inside InvoiceStore::run(). */
interface InvoiceTransaction
{
    /** Writes next only if the stored invoice still matches before; returns whether it did. */
    public function save(Invoice $before, Invoice $next): bool;

    public function addEvent(InvoiceVoided $event): void;
}
