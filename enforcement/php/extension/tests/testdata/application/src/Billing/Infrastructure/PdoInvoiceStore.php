<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure;

use App\Billing\Application\InvoiceStore;
use App\Billing\Domain\Invoice;
use Closure;
use Override;
use PDO;

final readonly class PdoInvoiceStore implements InvoiceStore
{
    private const string INVOICES = 'billing_invoices';

    public function __construct(
        private PDO $pdo,
    ) {}

    #[Override]
    public function load(string $id): ?Invoice
    {
        $statement = $this->pdo->prepare('SELECT id, organization_id, contact FROM ' . self::INVOICES . ' WHERE id = ?');
        if ($statement === false || !$statement->execute([$id])) {
            return null;
        }
        /** @var array{id: string, organization_id: string, contact: string}|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : new Invoice($row['id'], $row['organization_id'], $row['contact'], null);
    }

    #[Override]
    public function run(Closure $work): void
    {
        $this->pdo->beginTransaction();
        $work(new PdoInvoiceTransaction($this->pdo));
        $this->pdo->commit();
    }
}
