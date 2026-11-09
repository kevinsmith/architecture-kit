<?php

declare(strict_types=1);

namespace ArchitectureCheck\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function implode;
use function str_starts_with;

/**
 * Each forbidden fixture must fail with the expected issue code and rule ID; each permitted
 * fixture, written next to a form the extension rejects, must pass the whole analyzer.
 */
final class ExtensionTest extends TestCase
{
    private const string HEADER = "<?php\n\ndeclare(strict_types=1);\n\n";

    public function testTestApplicationPasses(): void
    {
        $analysis = Analysis::run();

        self::assertSame([], $analysis->summary(), $analysis->errors);
        self::assertSame(0, $analysis->exitCode, $analysis->errors);
    }

    /** @param array<string, mixed> $mapping */
    #[DataProvider('permitted')]
    public function testPermitted(string $path, string $source, array $mapping = []): void
    {
        $analysis = Analysis::run([$path => self::HEADER . $source], $mapping);

        self::assertSame([], $analysis->summary(), $analysis->errors);
        self::assertSame(0, $analysis->exitCode, $analysis->errors);
    }

    #[DataProvider('forbidden')]
    public function testForbidden(string $code, string $rule, string $path, string $source): void
    {
        $analysis = Analysis::run([$path => self::HEADER . $source]);

        $matching = [];
        foreach ($analysis->issues as $issue) {
            if ($issue['file'] === $path && $issue['code'] === "architecture/{$code}" && str_starts_with($issue['rule'], "{$rule}:")) {
                $matching[] = $issue;
            }
        }
        self::assertNotSame([], $matching, implode("\n", $analysis->summary()) . $analysis->errors);
        self::assertNotSame(0, $analysis->exitCode);
    }

    public function testInvalidMappingStopsAnalysis(): void
    {
        $analysis = Analysis::run(mapping: ['datasets' => ['Access' => ['shared_table'], 'Billing' => ['shared_table']]]);

        self::assertNotSame(0, $analysis->exitCode);
        self::assertStringContainsString('table `shared_table` has two owners', $analysis->errors);
    }

    /** @return iterable<string, array{0: string, 1: string, 2?: array<string, mixed>}> */
    public static function permitted(): iterable
    {
        yield 'fixed, parsed, and derived dates in Domain' => ['src/Billing/Domain/Deadline.php', <<<'PHP'
            namespace App\Billing\Domain;

            use DateTimeImmutable;

            final readonly class Deadline
            {
                public static function epoch(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2026-01-01T00:00:00Z');
                }

                public static function parse(string $date): DateTimeImmutable|false
                {
                    return DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                }

                public static function next(DateTimeImmutable $from): DateTimeImmutable
                {
                    return $from->modify('+1 day');
                }
            }
            PHP];

        yield 'common table expression over own tables, and prose that resembles SQL' => ['src/Billing/Infrastructure/InvoiceCount.php', <<<'PHP'
            namespace App\Billing\Infrastructure;

            use PDO;
            use RuntimeException;

            final readonly class InvoiceCount
            {
                private const string TABLE = 'billing_invoices';

                public function __construct(private PDO $pdo) {}

                public function voided(): int
                {
                    return $this->count('WITH voided AS (SELECT id FROM ' . self::TABLE . ' WHERE voided_at IS NOT NULL) '
                        . 'SELECT COUNT(*) FROM voided JOIN billing_outbox ON billing_outbox.invoice_id = voided.id');
                }

                private function count(string $sql): int
                {
                    $statement = $this->pdo->query($sql);
                    /** @var int|false $count */
                    $count = $statement === false ? false : $statement->fetchColumn();
                    if ($count === false) {
                        throw new RuntimeException('Update failed for the voided invoice count');
                    }

                    return $count;
                }
            }
            PHP];

        yield 'request input in a driving adapter' => ['src/Notifications/Presentation/RequestActor.php', <<<'PHP'
            namespace App\Notifications\Presentation;

            final readonly class RequestActor
            {
                public static function fromServer(): string
                {
                    return $_SERVER['HTTP_X_ACTOR'] ?? '';
                }
            }
            PHP];

        yield 'class name read from a value, and a string naming no symbol' => ['src/Billing/Application/Labels.php', <<<'PHP'
            namespace App\Billing\Application;

            use Closure;

            final readonly class Labels
            {
                /** @param Closure(string): string $format */
                public static function of(object $value, Closure $format): string
                {
                    return $format($value::class) . 'App\Billing\Unknown';
                }
            }
            PHP];

        yield 'effect after the transaction commits' => ['src/Billing/Application/Notice.php', <<<'PHP'
            namespace App\Billing\Application;

            use App\Billing\Contracts\InvoiceVoided;
            use App\Billing\Contracts\InvoiceVoidedSubscriber;

            final readonly class Notice
            {
                public function __construct(private InvoiceStore $store, private InvoiceVoidedSubscriber $subscriber) {}

                public function send(InvoiceVoided $event): void
                {
                    $this->store->run(static function (InvoiceTransaction $tx) use ($event): void {
                        $tx->addEvent($event);
                    });
                    $this->subscriber->deliver($event);
                }
            }
            PHP];

        yield 'contract with a recorded external consumer' => ['src/Billing/Contracts/Permissions.php', <<<'PHP'
            namespace App\Billing\Contracts;

            final readonly class Permissions
            {
                public const string VOID_INVOICE = 'invoice:void';
            }
            PHP, ['external_consumers' => ['App\Billing\Contracts\Permissions' => 'Grant administration runs outside this codebase.']]];

        yield 'use case holding a port and immutable configuration' => ['src/Billing/Application/Reminder.php', <<<'PHP'
            namespace App\Billing\Application;

            final class Reminder
            {
                /** @param list<string> $channels */
                public function __construct(
                    private readonly InvoiceStore $invoices,
                    private readonly array $channels,
                ) {}

                public function due(string $id): bool
                {
                    return $this->channels !== [] && $this->invoices->load($id) !== null;
                }
            }
            PHP];

        yield 'shared state in an Infrastructure adapter' => ['src/Billing/Infrastructure/StatusCache.php', <<<'PHP'
            namespace App\Billing\Infrastructure;

            use ArrayObject;

            final class StatusCache
            {
                private int $hits = 0;

                /** @param ArrayObject<string, string> $statuses */
                public function __construct(private readonly ArrayObject $statuses = new ArrayObject()) {}

                public function remember(string $id, string $status): void
                {
                    $this->hits++;
                    $this->statuses[$id] = $status;
                }
            }
            PHP];

        yield 'Domain entity mutating its own state' => ['src/Billing/Domain/Draft.php', <<<'PHP'
            namespace App\Billing\Domain;

            final class Draft
            {
                /** @var list<string> */
                private array $lines = [];

                public function add(string $line): void
                {
                    $this->lines[] = $line;
                }
            }
            PHP];
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function forbidden(): iterable
    {
        yield "another module's table" => ['table-ownership', 'R09', 'src/Billing/Infrastructure/GrantQuery.php', <<<'PHP'
            namespace App\Billing\Infrastructure;

            final readonly class GrantQuery
            {
                public const string SQL = 'SELECT actor FROM access_grants WHERE actor = ?';
            }
            PHP];

        yield 'undeclared table' => ['unowned-table', 'R09', 'src/Billing/Infrastructure/Ledger.php', <<<'PHP'
            namespace App\Billing\Infrastructure;

            final readonly class Ledger
            {
                public const string SQL = 'INSERT INTO billing_ledger (id) VALUES (?)';
            }
            PHP];

        yield 'table computed at runtime' => ['computed-table', 'R09', 'src/Billing/Infrastructure/Archive.php', <<<'PHP'
            namespace App\Billing\Infrastructure;

            final readonly class Archive
            {
                public static function sql(string $table): string
                {
                    return "DELETE FROM {$table} WHERE id = ?";
                }
            }
            PHP];

        yield 'effect inside a transaction' => ['transaction-effect', 'R13', 'src/Billing/Application/EagerNotice.php', <<<'PHP'
            namespace App\Billing\Application;

            use App\Billing\Contracts\InvoiceVoided;
            use App\Billing\Contracts\InvoiceVoidedSubscriber;

            final readonly class EagerNotice
            {
                public function __construct(private InvoiceStore $store, private InvoiceVoidedSubscriber $subscriber) {}

                public function send(InvoiceVoided $event): void
                {
                    $this->store->run(function (InvoiceTransaction $tx) use ($event): void {
                        $tx->addEvent($event);
                        $this->subscriber->deliver($event);
                    });
                }
            }
            PHP];

        yield 'effect function inside a transaction on an implementation' => ['transaction-effect', 'R13', 'src/Billing/Infrastructure/Resend.php', <<<'PHP'
            namespace App\Billing\Infrastructure;

            use App\Billing\Application\InvoiceTransaction;

            final readonly class Resend
            {
                public function __construct(private PdoInvoiceStore $store) {}

                public function go(): void
                {
                    $this->store->run(static function (InvoiceTransaction $tx): void {
                        unset($tx);
                        \mail('billing@example.com', 'Voided', 'An invoice was voided.');
                    });
                }
            }
            PHP];

        yield 'transaction work defined elsewhere' => ['transaction-callback', 'R13', 'src/Billing/Application/IndirectWork.php', <<<'PHP'
            namespace App\Billing\Application;

            final readonly class IndirectWork
            {
                public function __construct(private InvoiceStore $store) {}

                public function go(): void
                {
                    $this->store->run($this->work(...));
                }

                private function work(InvoiceTransaction $tx): void
                {
                    unset($tx);
                }
            }
            PHP];

        yield 'private model in a published docblock type' => ['private-type-published', 'R05', 'src/Billing/Contracts/InvoiceList.php', <<<'PHP'
            namespace App\Billing\Contracts;

            final readonly class InvoiceList
            {
                /** @param list<\App\Billing\Domain\Invoice> $invoices */
                public function __construct(public array $invoices) {}
            }
            PHP];

        yield 'mixed map in a published contract' => ['unstructured-published-type', 'R10', 'src/Billing/Contracts/Metadata.php', <<<'PHP'
            namespace App\Billing\Contracts;

            final readonly class Metadata
            {
                /** @param array<string, mixed> $values */
                public function __construct(public array $values) {}
            }
            PHP];

        yield 'object returned from a published contract' => ['unstructured-published-type', 'R10', 'src/Access/Contracts/Session.php', <<<'PHP'
            namespace App\Access\Contracts;

            interface Session
            {
                public function attributes(): object;
            }
            PHP];

        yield 'contract without an external consumer' => ['unconsumed-contract', 'R05', 'src/Billing/Contracts/InternalNote.php', <<<'PHP'
            namespace App\Billing\Contracts;

            final readonly class InternalNote
            {
                public function __construct(public string $text) {}
            }
            PHP];

        yield 'clock read in Domain' => ['ambient-clock', 'R04', 'src/Billing/Domain/Today.php', <<<'PHP'
            namespace App\Billing\Domain;

            use DateTimeImmutable;

            final readonly class Today
            {
                public static function value(): DateTimeImmutable
                {
                    return new DateTimeImmutable();
                }
            }
            PHP];

        yield 'relative date in Domain' => ['ambient-clock', 'R04', 'src/Billing/Domain/Tomorrow.php', <<<'PHP'
            namespace App\Billing\Domain;

            use DateTimeImmutable;

            final readonly class Tomorrow
            {
                public static function value(): DateTimeImmutable
                {
                    return new DateTimeImmutable('tomorrow');
                }
            }
            PHP];

        yield 'clock read through a parse format' => ['ambient-clock', 'R04', 'src/Billing/Domain/DueDate.php', <<<'PHP'
            namespace App\Billing\Domain;

            use DateTimeImmutable;

            final readonly class DueDate
            {
                public static function parse(string $date): DateTimeImmutable|false
                {
                    return DateTimeImmutable::createFromFormat('Y-m-d', $date);
                }
            }
            PHP];

        yield 'clock read in a published contract' => ['ambient-clock', 'R05', 'src/Billing/Contracts/Stamp.php', <<<'PHP'
            namespace App\Billing\Contracts;

            use DateTimeImmutable;

            final readonly class Stamp
            {
                public static function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('now');
                }
            }
            PHP];

        yield 'static state in Application' => ['static-state', 'R07', 'src/Billing/Application/Counter.php', <<<'PHP'
            namespace App\Billing\Application;

            final class Counter
            {
                private static int $count = 0;

                public static function next(): int
                {
                    return ++self::$count;
                }
            }
            PHP];

        yield 'writable property in a use case' => ['writable-property', 'R07', 'src/Billing/Application/LastActor.php', <<<'PHP'
            namespace App\Billing\Application;

            final class LastActor
            {
                private string $actor = '';

                public function handle(string $actor): string
                {
                    $previous = $this->actor;
                    $this->actor = $actor;

                    return $previous;
                }
            }
            PHP];

        yield 'mutable container in a readonly use case' => ['mutable-container', 'R07', 'src/Billing/Application/Scratch.php', <<<'PHP'
            namespace App\Billing\Application;

            use ArrayObject;

            final readonly class Scratch
            {
                /** @param ArrayObject<string, string> $scratch */
                public function __construct(private ArrayObject $scratch = new ArrayObject()) {}

                public function handle(string $id): ?string
                {
                    $previous = $this->scratch['invoice'] ?? null;
                    $this->scratch['invoice'] = $id;

                    return $previous;
                }
            }
            PHP];

        yield 'mutable container in a docblock collection type' => ['mutable-container', 'R07', 'src/Notifications/Presentation/Inbox.php', <<<'PHP'
            namespace App\Notifications\Presentation;

            use SplQueue;

            final readonly class Inbox
            {
                /** @param list<SplQueue<string>> $queues */
                public function __construct(private array $queues) {}

                public function count(): int
                {
                    return \count($this->queues);
                }
            }
            PHP];

        yield 'static variable in Domain' => ['static-state', 'R04', 'src/Billing/Domain/Sequence.php', <<<'PHP'
            namespace App\Billing\Domain;

            final readonly class Sequence
            {
                public static function next(): int
                {
                    static $next = 0;

                    return ++$next;
                }
            }
            PHP];

        yield 'request input outside a driving adapter' => ['ambient-input', 'R06', 'src/Billing/Application/HostName.php', <<<'PHP'
            namespace App\Billing\Application;

            final readonly class HostName
            {
                public static function value(): mixed
                {
                    return $_SERVER['HTTP_HOST'] ?? null;
                }
            }
            PHP];

        yield 'process configuration outside composition' => ['ambient-input', 'R06', 'src/Billing/Infrastructure/Dsn.php', <<<'PHP'
            namespace App\Billing\Infrastructure;

            final readonly class Dsn
            {
                public static function value(): string|false
                {
                    return \getenv('DATABASE_URL');
                }
            }
            PHP];

        yield 'included file in business code' => ['hidden-initialization', 'R06', 'src/Billing/Application/Setup.php', <<<'PHP'
            namespace App\Billing\Application;

            final readonly class Setup
            {
                public static function load(): mixed
                {
                    return require __DIR__ . '/settings.php';
                }
            }
            PHP];

        yield 'eval' => ['eval', 'R02', 'src/Billing/Application/Formula.php', <<<'PHP'
            namespace App\Billing\Application;

            final readonly class Formula
            {
                public static function run(): mixed
                {
                    return eval('return 1;');
                }
            }
            PHP];

        yield 'class named in a string' => ['string-dependency', 'R03', 'src/Notifications/Application/Lookup.php', <<<'PHP'
            namespace App\Notifications\Application;

            final readonly class Lookup
            {
                public const string TARGET = 'App\Billing\Infrastructure\PdoInvoiceStore';
            }
            PHP];

        yield 'class chosen at runtime' => ['dynamic-dependency', 'R03', 'src/Billing/Application/Factory.php', <<<'PHP'
            namespace App\Billing\Application;

            use App\Billing\Domain\Invoice;

            final readonly class Factory
            {
                /** @param class-string<Invoice> $class */
                public static function make(string $class): Invoice
                {
                    return new $class('invoice', 'organization', 'contact', null);
                }
            }
            PHP];

        yield 'function chosen at runtime' => ['dynamic-dependency', 'R03', 'src/Billing/Application/Callback.php', <<<'PHP'
            namespace App\Billing\Application;

            final readonly class Callback
            {
                public static function call(string $function): mixed
                {
                    return $function();
                }
            }
            PHP];
    }
}
