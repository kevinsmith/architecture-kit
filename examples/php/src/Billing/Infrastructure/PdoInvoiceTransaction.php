<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure;

use App\Billing\Application\InvoiceTransaction;
use App\Billing\Contracts\InvoiceVoided;
use App\Billing\Domain\Invoice;
use Override;
use PDO;
use PDOStatement;
use RuntimeException;

final readonly class PdoInvoiceTransaction implements InvoiceTransaction
{
    public function __construct(
        private PDO $pdo,
    ) {}

    #[Override]
    public function save(Invoice $before, Invoice $next): bool
    {
        $statement = $this->prepare(<<<'SQL'
            UPDATE billing_invoices SET status = ?, version = ?
            WHERE id = ? AND organization_id = ? AND status = ? AND version = ?
            SQL);
        $statement->execute([$next->status, $next->version, $before->id, $before->organizationId, $before->status, $before->version]);

        return $statement->rowCount() === 1;
    }

    #[Override]
    public function addEvent(InvoiceVoided $event): void
    {
        $this
            ->prepare('INSERT INTO billing_outbox (id, invoice_id, payload) VALUES (?, ?, ?)')
            ->execute([$event->id, $event->invoiceId, json_encode($event, JSON_THROW_ON_ERROR)]);
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('could not prepare statement');
        }

        return $statement;
    }
}
