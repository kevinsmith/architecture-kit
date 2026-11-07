<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Domain\Invoice;

interface InvoiceStore
{
    public function load(string $id): ?Invoice;

    /**
     * Commits when $work returns and rolls back when it throws, including an
     * expected abort, then rethrows. Nested units of work are not supported.
     *
     * @template T
     *
     * @param callable(InvoiceTransaction): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed;
}
