<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure;

use App\Billing\Application\InvoiceStore;
use App\Billing\Application\Outbox;
use App\Billing\Contracts\InvoiceVoided;
use App\Billing\Domain\Invoice;
use DateTimeImmutable;
use DateTimeZone;
use Override;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final readonly class PdoInvoiceStore implements InvoiceStore, Outbox
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function createSchema(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS billing_invoices (
                id TEXT PRIMARY KEY, organization_id TEXT NOT NULL, status TEXT NOT NULL,
                deadline INTEGER NOT NULL, version INTEGER NOT NULL, contact TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS billing_outbox (
                id TEXT PRIMARY KEY, invoice_id TEXT NOT NULL REFERENCES billing_invoices(id),
                payload TEXT NOT NULL, delivered INTEGER NOT NULL DEFAULT 0
            );
            SQL);
    }

    #[Override]
    public function load(string $id): ?Invoice
    {
        $statement = $this->prepare(<<<'SQL'
            SELECT id, organization_id, status, deadline, version, contact FROM billing_invoices WHERE id = ?
            SQL);
        $statement->execute([$id]);
        /** @var array{id: string, organization_id: string, status: string, deadline: int, version: int, contact: string}|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return new Invoice(
            $row['id'],
            $row['organization_id'],
            $row['status'],
            self::fromNanoseconds($row['deadline']),
            $row['version'],
            $row['contact'],
        );
    }

    #[Override]
    public function run(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work(new PdoInvoiceTransaction($this->pdo));
        } catch (Throwable $failure) {
            // SQLite ends the transaction itself on some errors, such as a full disk or
            // RAISE(ROLLBACK); rolling back again would replace the original failure.
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $failure;
        }
        try {
            $this->pdo->commit();
        } catch (Throwable $failure) {
            // SQLite keeps the transaction open when COMMIT fails, for example on a
            // deferred constraint. Roll back so tentative writes cannot leak into
            // the connection's next unit of work.
            if ($this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK');
            }
            throw $failure;
        }

        return $result;
    }

    #[Override]
    public function pending(): array
    {
        $statement = $this->pdo->query('SELECT payload FROM billing_outbox WHERE delivered = 0 ORDER BY id');
        if ($statement === false) {
            throw new RuntimeException('outbox query failed');
        }
        return \array_values(\array_map(static function (mixed $payload): InvoiceVoided {
            if (!\is_string($payload)) {
                throw new RuntimeException('outbox payload must be text');
            }

            return InvoiceVoided::fromJson($payload);
        }, $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    #[Override]
    public function acknowledge(string $eventId): void
    {
        $this->prepare('UPDATE billing_outbox SET delivered = 1 WHERE id = ?')->execute([$eventId]);
    }

    /**
     * Rounds up to PHP's microsecond precision, so that for a microsecond clock
     * `now < deadline` gives the same answer as it would in nanoseconds.
     */
    private static function fromNanoseconds(int $nanoseconds): DateTimeImmutable
    {
        $seconds = intdiv($nanoseconds, 1_000_000_000);
        $micros = intdiv($nanoseconds % 1_000_000_000 + 999, 1_000);
        if ($micros === 1_000_000) {
            $seconds++;
            $micros = 0;
        }
        $time = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $micros), new DateTimeZone('UTC'));
        if ($time === false) {
            throw new RuntimeException("invalid stored deadline {$nanoseconds}");
        }

        return $time;
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
