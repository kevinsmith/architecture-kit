<?php

declare(strict_types=1);

namespace Tests;

use App\Billing\Application\ConcurrentChange;
use App\Billing\Application\Denial;
use App\Billing\Application\Dispatcher;
use App\Billing\Application\InvoiceTransaction;
use App\Billing\Application\Voided;
use App\Billing\Application\VoidCommand;
use App\Billing\Application\VoidInvoice;
use App\Billing\Contracts\InvoiceVoided;
use App\Billing\Contracts\MalformedEvent;
use App\Billing\Contracts\Permissions;
use App\Billing\Contracts\Recipient;
use App\Billing\Domain\Invoice;
use App\Billing\Domain\Refusal;
use App\Billing\Infrastructure\PdoInvoiceStore;
use App\Bootstrap\Runtime;
use App\Notifications\Application\InvoiceVoidedNotifier;
use App\Notifications\Application\Message;
use App\Notifications\Presentation\MessageInput;
use DateTimeImmutable;
use Fiber;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\FailingSubscriber;
use Tests\Support\FrozenClock;
use Tests\Support\InterleavedStore;
use Tests\Support\Mailbox;
use Tests\Support\SuspendingAuthorizer;
use Tests\Support\SuspendingClock;
use Throwable;
use TypeError;
use UnitEnum;

/**
 * Executable evidence for the invoice-voiding scenario (examples/void-invoice.md),
 * against real on-disk SQLite. Raw SQL and the test's own adapter over a second
 * connection are test-only facilities for seeding, inspection, and failure
 * injection; composition exposes neither.
 */
final class VoidInvoiceScenarioTest extends TestCase
{
    private string $directory;

    private string $path;

    private Mailbox $mail;

    private Runtime $runtime;

    private PDO $db;

    private PdoInvoiceStore $invoices;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/invoice-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->path = $this->directory . '/invoice.db';
        $this->mail = new Mailbox();
        $this->open();
        $this->seed('inv', 'org-a', 'alice');
    }

    protected function tearDown(): void
    {
        unset($this->runtime, $this->db, $this->invoices);
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testV01DomainUsesExplicitInputs(): void
    {
        $invoice = new Invoice('inv', 'org-a', 'open', self::instant()->modify('+1 hour'), 0, 'c');
        $first = $invoice->void(self::instant());
        $second = $invoice->void(self::instant());
        self::assertInstanceOf(Invoice::class, $first);
        self::assertEquals($first, $second);
        self::assertSame(['open', 0], [$invoice->status, $invoice->version], 'decision mutated its input');
        self::assertSame(['voided', 1], [$first->status, $first->version]);
        self::assertSame(Refusal::Expired, $invoice->void($invoice->deadline), 'deadline boundary');
        self::assertInstanceOf(Invoice::class, $invoice->void($invoice->deadline->modify('-1 microsecond')));
    }

    public function testV02V04V10SuccessRepeatAndPushDelivery(): void
    {
        self::assertEquals(new Voided('inv:void:1'), $this->runtime->voidInvoice->handle('alice', self::command()));
        $this->assertState('voided', 1);
        self::assertSame([], $this->mail->messages, 'use case performed external delivery');

        self::assertSame(Refusal::AlreadyVoided, $this->runtime->voidInvoice->handle('alice', self::command()));
        $this->assertState('voided', 1);

        $this->runtime->dispatcher->dispatch();
        self::assertEquals([new Message('inv:void:1', 'inv@example.test', 'Invoice inv was voided.')], $this->mail->messages);
    }

    /** @return iterable<string, array{string, string, string, string, bool, UnitEnum}> */
    public static function rejections(): iterable
    {
        yield 'grant' => ['bob', 'org-a', 'open', '+1 hour', false, Denial::Forbidden];
        yield 'anonymous' => ['', 'org-a', 'open', '+1 hour', false, Denial::Forbidden];
        yield 'scope' => ['alice', 'org-b', 'open', '+1 hour', false, Denial::Forbidden];
        yield 'settled' => ['alice', 'org-a', 'settled', '+1 hour', false, Refusal::Settled];
        yield 'already-voided' => ['alice', 'org-a', 'voided', '+1 hour', false, Refusal::AlreadyVoided];
        yield 'expired' => ['alice', 'org-a', 'open', '+0 seconds', false, Refusal::Expired];
        yield 'invalid' => ['alice', 'org-a', 'broken', '+1 hour', false, Refusal::InvalidState];
        yield 'absent' => ['alice', 'org-a', 'open', '+1 hour', true, Denial::NotFound];
    }

    #[DataProvider('rejections')]
    public function testV03ExpectedRejections(string $actor, string $scope, string $status, string $deadline, bool $missing, UnitEnum $want): void
    {
        if ($scope === 'org-b') {
            // Permission in the claimed scope must not authorize a resource from another scope.
            $this->grant('alice', 'org-b');
        }
        $this->db->prepare('UPDATE billing_invoices SET status = ?, deadline = ?')
            ->execute([$status, self::nanoseconds(self::instant()->modify($deadline))]);
        $command = new VoidCommand($missing ? 'missing' : 'inv', $scope);

        self::assertSame($want, $this->runtime->voidInvoice->handle($actor, $command));
        $this->assertState($status, 0);
    }

    /** A stored deadline finer than PHP's microseconds still expires at its exact instant. */
    public function testV03StoredDeadlineKeepsItsPrecision(): void
    {
        $this->db->prepare('UPDATE billing_invoices SET deadline = ?')->execute([self::nanoseconds(self::instant()) + 1]);

        self::assertEquals(new Voided('inv:void:1'), $this->runtime->voidInvoice->handle('alice', self::command()), 'void before the deadline was refused');
    }

    /** @return iterable<string, array{Throwable}> */
    public static function aborts(): iterable
    {
        yield 'expected abort' => [new ConcurrentChange('abort after write')];
        yield 'operational failure' => [new RuntimeException('storage failure')];
        yield 'engine error' => [new TypeError('programming error')];
    }

    /** PHP has no context cancellation; an engine Error stands in for Go's panic. */
    #[DataProvider('aborts')]
    public function testV05ExplicitAbortAndUnexpectedFailure(Throwable $injected): void
    {
        try {
            $this->invoices->run(function (InvoiceTransaction $tx) use ($injected): never {
                $this->tentative($tx);
                throw $injected;
            });
            self::fail('failure reported success');
        } catch (Throwable $caught) {
            self::assertSame($injected, $caught, 'the failure lost its identity');
        }
        $this->assertState('open', 0);
        self::assertSame('done', $this->invoices->run(static fn(InvoiceTransaction $tx): string => 'done'), 'connection unusable after abort');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function writeFailures(): iterable
    {
        yield 'state' => ['fail_state', "CREATE TRIGGER fail_state BEFORE UPDATE ON billing_invoices BEGIN SELECT RAISE(ABORT, 'state failure'); END", 'state failure'];
        yield 'outbox' => ['fail_outbox', "CREATE TRIGGER fail_outbox BEFORE INSERT ON billing_outbox BEGIN SELECT RAISE(ABORT, 'outbox failure'); END", 'outbox failure'];
        // Stands in for errors after which SQLite rolls back by itself, such as a full disk.
        yield 'engine rollback' => ['fail_engine', "CREATE TRIGGER fail_engine BEFORE UPDATE ON billing_invoices BEGIN SELECT RAISE(ROLLBACK, 'engine rollback'); END", 'engine rollback'];
        yield 'commit' => ['fail_commit', <<<'SQL'
            CREATE TABLE commit_parent (id INTEGER PRIMARY KEY);
            CREATE TABLE commit_child (parent INTEGER REFERENCES commit_parent(id) DEFERRABLE INITIALLY DEFERRED);
            CREATE TRIGGER fail_commit AFTER INSERT ON billing_outbox BEGIN INSERT INTO commit_child VALUES (999); END
            SQL, 'FOREIGN KEY constraint failed'];
    }

    #[DataProvider('writeFailures')]
    public function testV06WriteOutboxAndCommitFailuresAreAtomic(string $trigger, string $setup, string $cause): void
    {
        $this->db->exec($setup);
        try {
            $this->runtime->voidInvoice->handle('alice', self::command());
            self::fail("failed {$trigger} reported success");
        } catch (PDOException $failure) {
            self::assertStringContainsString($cause, $failure->getMessage(), 'the failure lost its identity');
        }
        $this->assertState('open', 0);
        self::assertSame([], $this->mail->messages, 'delivery occurred on a failed transaction');

        $this->db->exec("DROP TRIGGER {$trigger}");
        self::assertEquals(new Voided('inv:void:1'), $this->runtime->voidInvoice->handle('alice', self::command()), 'connection unusable after failure');
        $this->assertState('voided', 1);
    }

    public function testV07UncommittedVisibilityAndDurableRetry(): void
    {
        $this->invoices->run(function (InvoiceTransaction $tx): void {
            $this->tentative($tx);
            // The runtime's dispatcher reads through its own connection.
            $this->runtime->dispatcher->dispatch();
            self::assertSame([], $this->mail->messages, 'dispatch observed an uncommitted intent');
        });

        $this->open(); // a fresh process over the same database
        $this->mail->failures = 1;
        try {
            $this->runtime->dispatcher->dispatch();
            self::fail('notification failure was swallowed');
        } catch (RuntimeException) {
        }
        $this->assertState('voided', 1);
        self::assertCount(1, $this->invoices->pending(), 'retry intent lost');

        $this->runtime->dispatcher->dispatch();
        self::assertSame([], $this->invoices->pending());
        self::assertSame(['inv:void:1'], $this->mail->eventIds());
    }

    public function testV07AcknowledgmentFailurePermitsDuplicateDelivery(): void
    {
        $this->runtime->voidInvoice->handle('alice', self::command());
        $this->db->exec("CREATE TRIGGER fail_ack BEFORE UPDATE ON billing_outbox BEGIN SELECT RAISE(ABORT, 'ack failure'); END");
        try {
            $this->runtime->dispatcher->dispatch();
            self::fail('acknowledgment failure was swallowed');
        } catch (PDOException) {
        }
        $this->db->exec('DROP TRIGGER fail_ack');
        $this->runtime->dispatcher->dispatch();

        self::assertSame(['inv:void:1', 'inv:void:1'], $this->mail->eventIds(), 'at-least-once, not exactly-once');
        $this->assertState('voided', 1);
    }

    /** @return iterable<string, array{string}> */
    public static function poisonedPayloads(): iterable
    {
        yield 'decode' => ['{"id":'];
        yield 'validation' => ['{"id":"inv-b:void:1"}'];
    }

    #[DataProvider('poisonedPayloads')]
    public function testV07InvalidPayloadBlocksBatchUntilRepaired(string $payload): void
    {
        $ids = $this->deliveryBatch();
        $original = $this->scalar('SELECT payload FROM billing_outbox WHERE id = ?', [$ids[1]]);
        $this->db->prepare('UPDATE billing_outbox SET payload = ? WHERE id = ?')->execute([$payload, $ids[1]]);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->runtime->dispatcher->dispatch();
                self::fail('invalid stored payload was accepted or silently skipped');
            } catch (MalformedEvent) {
            }
            $this->assertBatchDelivery([], $ids);
        }
        $this->db->prepare('UPDATE billing_outbox SET payload = ? WHERE id = ?')->execute([$original, $ids[1]]);
        $this->runtime->dispatcher->dispatch();
        $this->assertBatchDelivery($ids, []);
    }

    /** @return iterable<string, array{string}> */
    public static function persistentFailures(): iterable
    {
        yield 'delivery' => ['delivery'];
        yield 'acknowledgment' => ['acknowledgment'];
    }

    #[DataProvider('persistentFailures')]
    public function testV07PersistentDeliveryAndAcknowledgmentFailuresBlockLaterIntents(string $mode): void
    {
        $ids = $this->deliveryBatch();
        $permanent = new RuntimeException('permanent delivery failure');
        $dispatcher = $this->runtime->dispatcher;
        if ($mode === 'delivery') {
            $notifier = new InvoiceVoidedNotifier($this->mail);
            $dispatcher = new Dispatcher($this->invoices, new FailingSubscriber($notifier, $ids[1], $permanent));
        } else {
            $this->db->exec(<<<'SQL'
                CREATE TRIGGER fail_batch_ack BEFORE UPDATE OF delivered ON billing_outbox
                WHEN OLD.id = 'inv-b:void:1' BEGIN SELECT RAISE(ABORT, 'ack failure'); END
                SQL);
        }
        $messages = [$ids[0]];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $dispatcher->dispatch();
                self::fail("{$mode} failure was swallowed");
            } catch (Throwable $failure) {
                if ($mode === 'delivery') {
                    self::assertSame($permanent, $failure);
                } else {
                    self::assertInstanceOf(PDOException::class, $failure);
                    $messages[] = $ids[1];
                }
            }
            $this->assertBatchDelivery($messages, [$ids[1], $ids[2]]);
        }
        if ($mode === 'acknowledgment') {
            $this->db->exec('DROP TRIGGER fail_batch_ack');
        }
        $this->runtime->dispatcher->dispatch();
        $this->assertBatchDelivery([...$messages, $ids[1], $ids[2]], []);
    }

    public function testV08ConcurrentSettlementCannotBeOverwritten(): void
    {
        $settle = function (): void {
            $this->db->exec("UPDATE billing_invoices SET status = 'settled', version = version + 1 WHERE id = 'inv' AND status = 'open' AND version = 0");
        };
        $service = new VoidInvoice(new InterleavedStore($this->invoices, $settle(...)), $this->runtime->access, new FrozenClock(self::instant()));

        self::assertSame(Denial::Conflict, $service->handle('alice', self::command()), 'stale decision overwrote settlement');
        $this->assertState('settled', 0);
    }

    /**
     * The fixture has no settlement writer, so this demonstrates the conditional
     * write that every other writer must use, not an implementation of one.
     */
    public function testV08ConditionalWriteProtocolRejectsStaleSettlement(): void
    {
        $this->runtime->voidInvoice->handle('alice', self::command());
        $affected = $this->db->exec("UPDATE billing_invoices SET status = 'settled', version = version + 1 WHERE id = 'inv' AND status = 'open' AND version = 0");

        self::assertSame(0, $affected, 'stale settlement overwrote void');
        $this->assertState('voided', 1);
    }

    /**
     * Interleaves invocations of one shared service in one long-lived process
     * with Fibers. Each suspends after its read, before authorization, before
     * reading the clock, and before its transaction; every round resumes them
     * in the opposite order to the last, so any state the service carries
     * between those steps reaches another invocation.
     */
    public function testV09ConcurrentActorsAndTransactionsAreIsolated(): void
    {
        $count = 12;
        for ($i = 0; $i < $count; $i++) {
            $this->seed("inv-{$i}", "org-{$i}", "actor-{$i}");
        }
        $suspend = static function (): void {
            Fiber::suspend();
        };
        $service = new VoidInvoice(
            new InterleavedStore($this->invoices, $suspend, $suspend),
            new SuspendingAuthorizer($this->runtime->access),
            new SuspendingClock(new FrozenClock(self::instant())),
        );
        $fibers = [];
        for ($i = 0; $i < $count; $i++) {
            $actor = $i % 2 === 1 ? 'alice' : "actor-{$i}";
            $fibers[$i] = new Fiber(static fn(): object => $service->handle($actor, new VoidCommand("inv-{$i}", "org-{$i}")));
            $fibers[$i]->start();
        }
        for ($round = 0; $fibers !== []; $round++) {
            foreach ($round % 2 === 0 ? array_reverse($fibers, true) : $fibers as $i => $fiber) {
                $fiber->resume();
                if ($fiber->isTerminated()) {
                    $want = $i % 2 === 1 ? Denial::Forbidden : new Voided("inv-{$i}:void:1");
                    self::assertEquals($want, $fiber->getReturn(), "invocation {$i}");
                    unset($fibers[$i]);
                }
            }
        }
        self::assertSame(4, $round, 'each void suspends at its read, authorization, clock read, and transaction');
        for ($i = 0; $i < $count; $i++) {
            $invoice = $this->invoices->load("inv-{$i}");
            self::assertNotNull($invoice);
            self::assertSame([$i % 2 === 1 ? 'open' : 'voided', "org-{$i}"], [$invoice->status, $invoice->organizationId], "invocation {$i} affected the wrong state");
        }
        self::assertSame(6, $this->scalar('SELECT COUNT(*) FROM billing_outbox'));
    }

    public function testV11TypedCollectionAndRuntimeSchema(): void
    {
        $valid = '{"id":"e","invoice_id":"inv","organization_id":"org-a","occurred_at":"1791115200123456789","recipients":[{"address":"billing@example.test"}]}';
        $decoded = MessageInput::decode($valid);
        self::assertEquals([new Recipient('billing@example.test')], $decoded->recipients);
        $again = MessageInput::decode(json_encode($decoded, JSON_THROW_ON_ERROR));
        self::assertSame(1791115200123456789, $again->occurredAt, 'timestamp precision lost');
        $this->runtime->messages->receive($valid);
        self::assertEquals([new Message('e', 'billing@example.test', 'Invoice inv was voided.')], $this->mail->messages);

        $fields = '"id":"e","invoice_id":"inv","organization_id":"org-a"';
        foreach ([
            '{"payload":{"arbitrary":"shape"}}',
            "{{$fields},\"occurred_at\":\"1\",\"recipients\":[{\"address\":42}]}",
            "{{$fields},\"occurred_at\":\"1\",\"recipients\":[{\"address\":\"\"}]}",
            "{{$fields},\"occurred_at\":\"1\",\"recipients\":[]}",
            "{{$fields},\"occurred_at\":\"1\",\"recipients\":{\"a\":{\"address\":\"x\"}}}",
            "{{$fields},\"occurred_at\":\"1\",\"recipients\":{\"0\":{\"address\":\"x\"}}}",
            "{{$fields},\"occurred_at\":\"1\",\"recipients\":[{\"address\":\"x\",\"unknown\":true}]}",
            "{{$fields},\"occurred_at\":1791115200123456789,\"recipients\":[{\"address\":\"x\"}]}",
            "{{$fields},\"occurred_at\":\"99999999999999999999\",\"recipients\":[{\"address\":\"x\"}]}",
            "{{$fields},\"occurred_at\":\"1\",\"recipients\":[{\"address\":\"x\"}],\"extra\":1}",
            $valid . '{}',
            'null',
            '[]',
        ] as $payload) {
            try {
                $this->runtime->messages->receive($payload);
                self::fail("invalid representation accepted: {$payload}");
            } catch (MalformedEvent) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertCount(1, $this->mail->messages, 'a rejected message was delivered');
    }

    private function open(): void
    {
        $this->runtime = Runtime::open($this->path, $this->mail, new FrozenClock(self::instant()));
        $this->db = new PDO('sqlite:' . $this->path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $this->db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
        $this->invoices = new PdoInvoiceStore($this->db);
    }

    private function seed(string $id, string $organization, string $actor): void
    {
        $this->db->prepare("INSERT INTO billing_invoices VALUES (?, ?, 'open', ?, 0, ?)")
            ->execute([$id, $organization, self::nanoseconds(self::instant()->modify('+1 hour')), "{$id}@example.test"]);
        $this->grant($actor, $organization);
    }

    private function grant(string $actor, string $organization): void
    {
        $this->db->prepare('INSERT OR IGNORE INTO access_grants VALUES (?, ?, ?)')
            ->execute([$actor, Permissions::VOID_INVOICE, $organization]);
    }

    private function tentative(InvoiceTransaction $tx): void
    {
        $before = new Invoice('inv', 'org-a', 'open', self::instant(), 0, 'inv@example.test');
        $next = new Invoice('inv', 'org-a', 'voided', self::instant(), 1, 'inv@example.test');
        self::assertTrue($tx->save($before, $next), 'fixture write conflicted');
        $tx->addEvent(new InvoiceVoided('inv:void:1', 'inv', 'org-a', self::nanoseconds(self::instant()), [new Recipient('inv@example.test')]));
    }

    /** @return list<string> */
    private function deliveryBatch(): array
    {
        $ids = [];
        foreach (['inv-a', 'inv-b', 'inv-c'] as $id) {
            $this->seed($id, 'org-a', 'alice');
            self::assertInstanceOf(Voided::class, $this->runtime->voidInvoice->handle('alice', new VoidCommand($id, 'org-a')));
            $ids[] = "{$id}:void:1";
        }

        return $ids;
    }

    /**
     * @param list<string> $messages
     * @param list<string> $pending
     */
    private function assertBatchDelivery(array $messages, array $pending): void
    {
        self::assertSame($messages, $this->mail->eventIds(), 'delivered event IDs');
        // Read raw rows, since a poisoned payload prevents decoding them.
        $statement = $this->db->query('SELECT id FROM billing_outbox WHERE delivered = 0 ORDER BY id');
        self::assertNotFalse($statement);
        self::assertSame($pending, $statement->fetchAll(PDO::FETCH_COLUMN), 'pending event IDs');
        self::assertSame(3, $this->scalar('SELECT COUNT(*) FROM billing_outbox'), 'dispatch changed committed intents');
        self::assertSame(3, $this->scalar("SELECT COUNT(*) FROM billing_invoices WHERE status = 'voided' AND version = 1"), 'dispatch changed committed state');
    }

    private function assertState(string $status, int $intents): void
    {
        $invoice = $this->invoices->load('inv');
        self::assertNotNull($invoice);
        self::assertSame($status, $invoice->status);
        self::assertSame($intents, $this->scalar('SELECT COUNT(*) FROM billing_outbox'), 'intents');
    }

    /** @param list<string> $parameters */
    private function scalar(string $sql, array $parameters = []): mixed
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn();
    }

    private static function command(): VoidCommand
    {
        return new VoidCommand('inv', 'org-a');
    }

    private static function instant(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-04T12:00:00Z');
    }

    private static function nanoseconds(DateTimeImmutable $time): int
    {
        return (int) $time->format('U') * 1_000_000_000 + (int) $time->format('u') * 1_000;
    }
}
