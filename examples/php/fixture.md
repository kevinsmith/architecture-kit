# PHP invoice-voiding fixture

Executable validation of the shared [scenario](../void-invoice.md), using PHP 8.5.12, PDO SQLite, PHPUnit 13.4.6, and Mago 1.59.0. This is a bounded reference fixture, not a production application or a universal PHP layout.

## Run

From this directory:

```sh
composer install
vendor/bin/mago guard
vendor/bin/mago analyze
vendor/bin/phpunit
```

`python3 scripts/verify.py` from the repository root is the source of truth: it runs these and all other checks in pinned Docker images. See [verification prerequisites](../../README.md#verify-this-repository). It also runs the [guard fixtures](../../enforcement/php/fixtures.py) against an isolated copy of this application. Dependencies are locked in `composer.lock`.

Tests use temporary on-disk SQLite databases with foreign keys, WAL, and a busy timeout. No external database server or mail service is required.

Each module's storage adapter creates its own tables with `CREATE TABLE IF NOT EXISTS` when composition opens the connection. This is a fixture shortcut that works only because every test database starts empty and is reopened only by the same code: it cannot alter an existing table or record which schema version is applied. An application with persistent data uses versioned migrations instead, kept with each module's Infrastructure and changing only that module's tables (R09), and run by composition or deployment, never by request handling.

## Architecture mapping

[`mago.toml`](mago.toml) classifies every symbol by namespace and declares module dependencies. Names are illustrative:

- `App\Billing\Domain`, `App\Access\Domain`: pure decisions over supplied values. `Invoice::void()` returns the next state or a `Refusal`.
- `App\Billing\Application`, `App\Access\Application`, `App\Notifications\Application`: use cases, module-internal ports, and the outbox dispatcher.
- `App\Billing\Infrastructure`, `App\Access\Infrastructure`: PDO adapters accessing only their owner's tables.
- `App\Notifications\Presentation`: a message-consumer boundary with runtime JSON validation.
- `App\Billing\Contracts`, `App\Access\Contracts`: owner-published contracts: the `InvoiceVoided` event and its subscription port, and the `Authorizer` call contract.
- `App\Platform`: one shared system clock behind PSR-20, supplied to Application, never Domain.
- `App\Bootstrap`: explicit composition. `Runtime` exposes use cases, the dispatcher, and the message input, never adapters or the connection.

The declared module graph is Billing → Access and Notifications → Billing. Billing calls Access's published `Authorizer`. Notifications implements Billing's published `InvoiceVoidedSubscriber`, because a PHP class must name the interface it implements; Billing never names Notifications.

## Execution contract

- **Identity:** the caller supplies a trusted actor. Billing checks the claimed scope against the invoice's stored organization, then asks Access about that stored scope.
- **Time:** Application reads the PSR-20 clock and passes the time to Domain. The deadline is evaluated at the decision time, not at commit time.
- **Event encoding:** the same wire format as the Go fixture. `occurred_at` is UTC Unix nanoseconds as a JSON decimal string. Decoding accepts exactly the published fields.
- **Outcomes:** `VoidInvoice::handle()` returns `Voided` after commit, a `Denial` for absence, authorization, and conflicts, or the Domain's `Refusal`. Unexpected failures are exceptions and keep their identity.
- **Transactions:** `InvoiceStore::run()` commits when the callable returns and rolls back and rethrows when it throws. A conflicting conditional write throws `ConcurrentChange`, which the use case turns into `Denial::Conflict` after the rollback. Nested units of work are not supported.
- **Commit failure:** SQLite keeps the transaction open when `COMMIT` fails on a deferred constraint. The adapter rolls back explicitly, and an integration test verifies rollback and connection reuse.
- **Concurrency:** Billing reads a snapshot and conditionally updates by identity, organization, status, and version. Every competing writer must preserve that protocol. A stale void returns `Denial::Conflict` and is not retried.
- **Delivery:** invoice state and the event intent commit together. The dispatcher reads committed intents only, delivers at least once, and stops at the first failure, leaving later intents pending. There is no retry scheduler, quarantine, or worker lease.
- **Lifetimes:** every service is `readonly` and holds no invocation state, so one `Runtime` can serve a long-lived worker. The suite interleaves invocations with Fibers at each step of the use case to show actors and scopes do not leak.

## Evidence and remaining obligations

| Scenario | Tests / evidence |
|---|---|
| V01 | Repeated decisions with the same inputs, an unmutated input, and both sides of the deadline |
| V02, V04, V10 | Successful void, repeat refusal without another intent, and push-shaped delivery |
| V03 | Missing grant, anonymous actor, mismatched scope, settled, voided, invalid state, expiry, absence, and a stored deadline finer than a microsecond |
| V05 | An expected abort, an operational exception, and an engine `TypeError` after a tentative write, with preserved identity and a usable connection |
| V06 | State-write, outbox-write, engine-initiated rollback, and real deferred-constraint commit failures, each keeping its original exception; rollback and healthy reuse |
| V07 | Uncommitted intents invisible to a separate connection, retry after reopening, invalid payloads and persistent failures blocking the batch, recovery, and duplicate delivery |
| V08 | Settlement interleaved between a void's read and write; the conditional write that any other writer must use, shown with the test's own SQL because the fixture has no settlement writer |
| V09 | Twelve invocations of one shared use case with different actors and scopes, Fiber-interleaved after the read, before authorization, before the clock read, and before the transaction |
| V11 | Typed recipient list, delivery through the message input, and rejection there of missing, malformed, unknown, oversized, and trailing data and of an object in place of a list |

The guard fixtures cover the structural pairs in the scenario. PHP has no context cancellation, so V05 has no cancellation case. Fibers run in one process and show deterministic interleaving, not parallel execution; cross-process races rely on SQLite's locking and the conditional write. R09 table ownership and R13 transaction effects are review obligations here: the guard cannot read SQL or follow callables. Reporting (R15), authentication, nested transactions, contract evolution, and other database engines are outside this fixture's validated scope.
