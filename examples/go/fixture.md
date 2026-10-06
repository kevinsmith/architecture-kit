# Go invoice-voiding fixture

Executable validation of the shared [scenario](../void-invoice.md), using Go 1.27.1 and `modernc.org/sqlite` v1.38.2. This is a bounded reference fixture, not a production application or a universal Go layout.

## Run

From this directory:

```sh
go test -race -count=1 -timeout=120s ./...
```

For all checks, or without local Go, run `python3 scripts/verify.py` from the repository root. See [verification prerequisites and Docker options](../../README.md#verify-this-repository). The pinned image digest lives in that script; dependencies are locked in `go.mod`/`go.sum`.

Tests use temporary on-disk SQLite databases with foreign keys, WAL, and a busy timeout. No external database server or mail service is required.

## Architecture mapping

[`architecture.json`](architecture.json) classifies every production package and declares module dependencies. Names are illustrative:

- `billing/domain` and `access/grant.go`: pure decisions over supplied values. Access's file-role override keeps the small capability in one package without dropping its logical boundary.
- `billing`, `access`, `notifications`: Application functions/services and consumer-owned interfaces.
- `billing/sqlite`, `access/sqlite`: adapters accessing only their owner's tables.
- `notifications/input`: a driving boundary with runtime JSON validation.
- `billing/contracts`: owner-published event data, explicit typed recipients, and intrinsic validation; no central contracts package is required.
- `platform`: one shared system clock, supplied to Application, never Domain.
- `assembly`: explicit composition and database-driver registration. The fixture needs no HTTP server or DI container.

Billing consumes an interface that Access satisfies without importing Billing. Notifications likewise satisfies Billing's subscriber interface without importing it. Shared event data connects them without exposing private entities. The declared module graph is Billing → Access and Notifications → Billing; Billing does not name its subscribers, and the Billing → Access dependency appears only in composition, where the checker verifies it.

## Execution contract

- **Identity:** the fixture's driving caller supplies a trusted actor. It is not an authentication implementation. Billing checks the requested scope against the invoice's actual organization, then asks Access about that actual scope.
- **Time:** the deadline is evaluated at Application's supplied decision time, not at eventual commit time. Domain receives `time.Time` values and makes no external calls.
- **Event encoding:** `OccurredAt` is UTC Unix nanoseconds encoded as a JSON decimal string, preserving int64 precision across language boundaries. Runtime validation rejects numeric JSON timestamps and tests round-trip a nanosecond value larger than float64's exact integer range.
- **Database ownership:** adapter pools and the runtime's database handle are private. Composition supplies them through constructors and exposes only use cases, the dispatcher, and `Close`, never adapters. The integration suite opens a separate test-only handle, and builds its own Billing adapter over it, for seeding, inspecting committed state, and injecting failures; neither is a module API or a permissions fence.
- **Cancellation:** the caller's context controls `database/sql.BeginTx`; cancellation before commit aborts the unit. The fixture tests cancellation after tentative writes. Cancellation after a successful commit cannot undo it. A connection-loss commit error in another driver can leave an unknown outcome requiring reconciliation, unlike this fixture's known deferred-constraint rollback.
- **Concurrency:** Billing reads a snapshot and conditionally updates by identity, organization, state, and version. Every competing writer must preserve that version protocol. A stale void returns `Conflict`; it is not automatically retried.
- **Authorization freshness:** grants are checked for the invocation before the write transaction. Atomic revocation-at-commit is not promised. A project requiring it needs a stronger consistency contract.
- **Errors:** `Void` returns nil on success and sentinel errors for expected rejection. Domain error identities are exposed at the Application boundary without a duplicate outcome hierarchy. Callers use `errors.Is`; wrapped operational errors retain their causes and are not converted to rejections.
- **Transactions:** `Store.Run` supplies a transaction-bound repository interface and commits only when its callback returns nil. Any error, including an expected rejection, rolls back; panics unwind through deferred rollback. Transaction control knows nothing about business outcomes. The fixture does not support nested use cases/transactions; callbacks must use the supplied transaction, not capture another store and open an independent unit of work.
- **Commit failure:** this SQLite driver can leave the physical transaction active after `sql.Tx.Commit` fails and the Go transaction is marked done. The adapter reserves a connection and discards it on commit failure. A deferred-constraint integration test verifies rollback and subsequent pool usability. Do not generalize these exact driver semantics to another database.
- **Delivery:** invoice state and an event intent commit together. A separate dispatcher reads only committed intents. Failure leaves work retryable, including across database reopen. Acknowledgment occurs after sending, so a crash/ack failure can cause duplicate delivery. This fixture demonstrates that explicitly; it does not implement exactly-once email or a concurrent-worker lease protocol.
- **Failure recovery:** Dispatch is fail-fast. An invalid stored payload prevents the pending batch from being dispatched. A delivery or acknowledgment error stops that run, leaving unacknowledged intents pending. The caller receives the error; correcting the failure and invoking dispatch again is the recovery path. The fixture has no automatic quarantine, retry scheduler, or retry limit.

## Evidence and remaining obligations

| Scenario | Tests / evidence |
|---|---|
| V01 | Explicit-input decision and deadline-boundary tests; source inspection confirms Domain uses only its values and deterministic time comparison |
| V02, V04, V10 | Successful void, repeat rejection without another intent, and push-shaped recipient/message delivery |
| V03 | Missing grant, anonymous actor, mismatched scope, settled/voided/invalid state, expiry, absence |
| V05 | Wrapped expected rejection after tentative writes, wrapped operational error with preserved identity, panic, and cancellation |
| V06 | State-write failure, outbox-write failure, and real deferred-constraint commit failure; rollback and healthy reuse |
| V07 | Visibility from a separate connection before commit, reopen/retry, batch blocking on invalid payloads and persistent delivery/acknowledgment failures, recovery after correction, and demonstrated duplicate delivery |
| V08 | Deterministically interleaved stale void versus settlement and the opposite write order |
| V09 | Concurrent actors/scopes sharing services and a connection pool, run with the race detector |
| V11 | Typed recipient collection and rejection of missing, malformed, unknown, and trailing representation data |

The [checker](../../enforcement/go/development.md) supplies partial structural evidence and positive/negative fixtures. It does not prove all effects or semantic ownership. R09 evidence here includes the checker's ownership check of each adapter's constant SQL; the shared database credential is not a permissions-based module fence. Notification tests use a recording/failing mail adapter, not a real provider. Race tests exercise specific interleavings, not every possible execution. Reporting (R15), authentication, nested transactions, contract evolution across schema revisions, other database engines, and deployed platform/build variants are outside this fixture's validated scope.
