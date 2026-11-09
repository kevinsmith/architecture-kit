<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Domain\Invoice;
use Closure;

interface InvoiceStore
{
    public function load(string $id): ?Invoice;

    /** @param Closure(InvoiceTransaction): void $work */
    public function run(Closure $work): void;
}
