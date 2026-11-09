<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure;

use App\Billing\Application\InvoiceTransaction;
use App\Billing\Contracts\InvoiceVoided;
use App\Billing\Domain\Invoice;
use Override;
use PDO;
use RuntimeException;

final readonly class PdoInvoiceTransaction implements InvoiceTransaction
{
    public function __construct(
        private PDO $pdo,
    ) {}

    #[Override]
    public function save(Invoice $invoice): void
    {
        $this->execute(<<<'SQL'
            UPDATE billing_invoices SET voided_at = ? WHERE id = ?
            SQL, [$invoice->voidedAt?->format('U'), $invoice->id]);
    }

    #[Override]
    public function addEvent(InvoiceVoided $event): void
    {
        $this->execute('INSERT INTO billing_outbox (invoice_id) VALUES (?)', [$event->invoiceId]);
    }

    /** @param list<string|null> $values */
    private function execute(string $sql, array $values): void
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false || !$statement->execute($values)) {
            throw new RuntimeException('statement failed');
        }
    }
}
