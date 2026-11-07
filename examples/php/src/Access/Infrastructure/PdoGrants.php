<?php

declare(strict_types=1);

namespace App\Access\Infrastructure;

use App\Access\Application\Grants;
use App\Access\Domain\Grant;
use Override;
use PDO;
use PDOStatement;
use RuntimeException;

final readonly class PdoGrants implements Grants
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function createSchema(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS access_grants (
                actor TEXT NOT NULL, permission TEXT NOT NULL, organization TEXT NOT NULL,
                PRIMARY KEY (actor, permission, organization)
            )
            SQL);
    }

    #[Override]
    public function find(string $actor, string $permission, string $organization): ?Grant
    {
        $statement = $this->prepare(<<<'SQL'
            SELECT actor, permission, organization FROM access_grants
            WHERE actor = ? AND permission = ? AND organization = ?
            SQL);
        $statement->execute([$actor, $permission, $organization]);
        /** @var array{actor: string, permission: string, organization: string}|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : new Grant($row['actor'], $row['permission'], $row['organization']);
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
