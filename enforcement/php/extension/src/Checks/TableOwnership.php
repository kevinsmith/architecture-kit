<?php

declare(strict_types=1);

namespace ArchitectureCheck\Checks;

use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Override;
use ArchitectureCheck\Check;
use ArchitectureCheck\Code;
use ArchitectureCheck\Layout;
use ArchitectureCheck\Place;
use ArchitectureCheck\Report;
use ArchitectureCheck\Sql;

/**
 * R09: SQL statements written in a module name only tables that module owns. Every string
 * expression is read, so SQL passed through helpers and constants is checked too.
 */
final readonly class TableOwnership implements Check
{
    public function __construct(
        private Layout $layout,
    ) {}

    #[Override]
    public function check(Code $code, Place $place, Node $namespace, Report $report): void
    {
        if (!$this->layout->hasDatasets()) {
            return;
        }
        $roots = [];
        foreach ($code->within($namespace, NodeKind::LiteralString, NodeKind::DocumentString, NodeKind::InterpolatedString) as $node) {
            $root = $code->stringRoot($node);
            $roots[$root->id] = $root;
        }
        foreach ($roots as $root) {
            foreach (Sql::tables($code->string($root)) as $table) {
                $owner = $this->layout->owner($table);
                if (Sql::isComputed($table)) {
                    $report->error(
                        $root,
                        'computed-table',
                        'R09',
                        'SQL names a table computed at runtime.',
                        'ownership is verifiable only for tables named in the statement text.',
                        'Name the table literally, or choose between complete literal statements.',
                    );
                } elseif ($owner === null) {
                    $report->error(
                        $root,
                        'unowned-table',
                        'R09',
                        "Table `{$table}` has no declared owner.",
                        'every mutable dataset has exactly one owning module.',
                        "Add `{$table}` to its owning module's datasets in the architecture mapping.",
                    );
                } elseif ($owner !== $place->module) {
                    $report->error(
                        $root,
                        'table-ownership',
                        'R09',
                        "{$place->module} code uses {$owner}'s table `{$table}`.",
                        "other modules read or change a dataset only through its owner's contracts or an approved reporting path (R15).",
                        "Call a {$owner} contract instead of its storage.",
                    );
                }
            }
        }
    }
}
