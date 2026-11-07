<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Billing\Application\InvoiceStore;
use App\Billing\Domain\Invoice;
use Closure;

/**
 * Runs $afterLoad between a use case's load and its write, and $beforeRun just
 * before the transaction starts, which interleaves another actor deterministically.
 * Inside a Fiber, either may suspend it; neither runs inside the transaction,
 * because invocations sharing one connection must not suspend there.
 */
final readonly class InterleavedStore implements InvoiceStore
{
    public function __construct(
        private InvoiceStore $inner,
        private Closure $afterLoad,
        private ?Closure $beforeRun = null,
    ) {}

    public function load(string $id): ?Invoice
    {
        $invoice = $this->inner->load($id);
        ($this->afterLoad)($id);

        return $invoice;
    }

    public function run(callable $work): mixed
    {
        if ($this->beforeRun !== null) {
            ($this->beforeRun)();
        }

        return $this->inner->run($work);
    }
}
