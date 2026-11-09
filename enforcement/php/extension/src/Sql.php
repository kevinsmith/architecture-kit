<?php

declare(strict_types=1);

namespace ArchitectureCheck;

use function array_pop;
use function count;
use function ctype_alpha;
use function in_array;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function str_contains;
use function strcasecmp;
use function strtolower;
use function strtoupper;
use function trim;

/** Extracts the tables a SQL statement reads or writes (R09). */
final class Sql
{
    /** Stands in for a computed part of a string, so a computed table name stays visible. */
    public const string COMPUTED = '__computed__';

    private const string COMMENTS = '~--[^\n]*|/\*.*?\*/~s';
    private const string TOKENS = '~\'(?:[^\']|\'\')*\'|"[^"]*"|`[^`]*`|\[[^\]]*\]|[A-Za-z_][A-Za-z0-9_$]*(?:\.[A-Za-z_][A-Za-z0-9_$]*)*|\S~';

    /** A complete statement shape, so prose such as "Update failed" is not read as SQL. */
    private const string STATEMENT = '~^\s*(?:select\b.+\bfrom\b|(?:insert|replace)\s+(?:or\s+\w+\s+)?into\b|update\b.+\bset\b'
        . '|delete\s+from\b|merge\s+into\b|truncate\s+table\b|with\b.+\bas\s*\('
        . '|(?:create|alter|drop)\s+(?:(?:temp|temporary|unique|virtual)\s+)?(?:table|index|view|trigger)\b)~is';

    private const array RESERVED = [
        'WHERE', 'JOIN', 'INNER', 'LEFT', 'RIGHT', 'FULL', 'OUTER', 'CROSS', 'NATURAL', 'ON', 'USING', 'GROUP', 'ORDER',
        'LIMIT', 'OFFSET', 'UNION', 'EXCEPT', 'INTERSECT', 'HAVING', 'WINDOW', 'SET', 'VALUES', 'RETURNING', 'FOR',
        'SELECT', 'SKIP', 'NOWAIT', 'OF',
    ];

    /** FROM inside these functions separates arguments rather than naming a table. */
    private const array FROM_FUNCTIONS = ['EXTRACT', 'TRIM', 'SUBSTRING', 'SUBSTR', 'OVERLAY', 'POSITION'];

    private const array MODIFIERS = ['IF', 'NOT', 'EXISTS', 'ONLY', 'LATERAL', 'TABLE'];

    /**
     * Returns the tables named after FROM, JOIN, INTO, UPDATE, TABLE, TRUNCATE, and
     * REFERENCES. Excludes common table expressions, table-valued function calls, upsert
     * and row-locking clauses, and FROM inside functions such as EXTRACT or in IS DISTINCT FROM.
     *
     * @return list<string>
     */
    public static function tables(string $sql): array
    {
        $sql = (string) preg_replace(self::COMMENTS, ' ', $sql);
        if (preg_match(self::STATEMENT, $sql) !== 1) {
            return [];
        }
        $matches = [];
        preg_match_all(self::TOKENS, $sql, $matches);
        /** @var list<string> $tokens */
        $tokens = $matches[0];
        $count = count($tokens);
        $upper = static fn(int $i): string => strtoupper($tokens[$i] ?? '');
        $identifier = static function (int $i) use ($tokens): bool {
            $token = $tokens[$i] ?? '';

            return $token !== '' && (in_array($token[0], ['"', '`', '[', '_'], true) || ctype_alpha($token[0]));
        };
        $name = static fn(string $token): string => trim($token, '"`[]');

        $ctes = [];
        for ($i = 1; $i + 2 < $count; $i++) {
            if (in_array($upper($i - 1), ['WITH', 'RECURSIVE', ','], true) && $upper($i + 1) === 'AS' && $tokens[$i + 2] === '(') {
                $ctes[strtolower($name($tokens[$i]))] = true;
            }
        }

        // Mark FROM tokens that are function arguments rather than clauses.
        $argument = [];
        $calls = [];
        foreach ($tokens as $i => $token) {
            if ($token === '(') {
                $calls[] = $i > 0 ? $upper($i - 1) : '';
            } elseif ($token === ')' && $calls !== []) {
                array_pop($calls);
            } elseif (strcasecmp($token, 'FROM') === 0) {
                $inFunction = $calls !== [] && in_array($calls[count($calls) - 1], self::FROM_FUNCTIONS, true);
                $distinct = $i > 1 && $upper($i - 1) === 'DISTINCT' && in_array($upper($i - 2), ['IS', 'NOT'], true);
                $argument[$i] = $inFunction || $distinct;
            }
        }

        $isTable = static fn(int $i): bool => $identifier($i)
            && !in_array($upper($i), self::RESERVED, true)
            && !(
                ($tokens[$i + 1] ?? '') === '('
                && !in_array($upper($i - 1), ['INTO', 'REFERENCES', 'TABLE'], true)
                && !in_array($upper($i - 1), self::MODIFIERS, true)
            );

        $tables = [];
        $seen = [];
        $add = static function (int $i) use ($tokens, $name, $ctes, &$tables, &$seen): void {
            $table = $name($tokens[$i]);
            $key = strtolower($table);
            if (!isset($ctes[$key]) && !isset($seen[$key])) {
                $seen[$key] = true;
                $tables[] = $table;
            }
        };

        foreach ($tokens as $i => $token) {
            $keyword = strtoupper($token);
            if (!in_array($keyword, ['FROM', 'JOIN', 'INTO', 'UPDATE', 'TABLE', 'TRUNCATE', 'REFERENCES'], true)) {
                continue;
            }
            if (($argument[$i] ?? false) || ($keyword === 'UPDATE' && $i > 0 && in_array($upper($i - 1), ['FOR', 'DO', 'KEY'], true))) {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && in_array($upper($j), self::MODIFIERS, true)) {
                $j++;
            }
            if (!$isTable($j)) {
                continue;
            }
            $add($j);
            if ($keyword !== 'FROM') {
                continue;
            }
            // FROM a x, b AS y
            for ($k = $j + 1; $k < $count;) {
                if ($upper($k) === 'AS' || ($identifier($k) && !in_array($upper($k), self::RESERVED, true))) {
                    $k++;
                } elseif ($tokens[$k] === ',' && $identifier($k + 1)) {
                    if ($isTable($k + 1)) {
                        $add($k + 1);
                    }
                    $k += 2;
                } else {
                    break;
                }
            }
        }

        return $tables;
    }

    public static function isComputed(string $table): bool
    {
        return str_contains($table, self::COMPUTED);
    }
}
