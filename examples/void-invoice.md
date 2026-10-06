# Reference scenario: void an invoice

This is a verification specification for [R01–R17](../kit/docs/architecture/rules.md). The [Go fixture](go/fixture.md) supplies executable evidence with documented limits. The business policy below exercises architectural guarantees; it is not part of the universal architecture.

## Ownership and boundaries

- **Billing** owns invoices, their organization, status, void deadline, and a billing-contact snapshot used for notifications.
- **Access** owns permission grants and publishes a scoped authorization contract. Billing supplies the actor, requested permission, and the invoice's actual scope; Access does not fetch invoices.
- **Notifications** receives schema-defined delivery data. It does not query Billing or Access to assemble a message.
- **Composition** wires adapters, shared time, persistence, authorization, and the post-commit delivery path. No transport or container is required by the scenario.
- **Module dependencies:** Billing depends on Access, whose contract it calls. Notifications depends on Billing, whose event it subscribes to. Billing does not name its subscribers.

## Fixture policy

An authenticated actor may void an open invoice before its deadline only when permitted in the invoice's organization. The transition must not overwrite a concurrent settlement. Already-voided, settled, expired, unauthorized, absent, and concurrency-conflict cases are explicit expected outcomes. Repeating a completed void does not create another event or notification intent.

Delivery is durable for this fixture. This deliberately exercises the outbox option in R13; it does not require every adopting workflow to use an outbox.

## Workflow

1. Receive validated command data and trusted actor context through a driving adapter.
2. Load the invoice through Billing's persistence contract. Obtain authorization for its actual scope through Access's published contract. A caller-supplied scope is not sufficient evidence of ownership.
3. Application obtains the current time. Pure Domain evaluates invoice state and time, returning either a rejection or a proposed state transition. It does not call Access, a clock, a repository, or a dispatcher.
4. Apply the transition with transaction-bound persistence and suitable concurrency control. The stored state and durable `InvoiceVoided` delivery intent commit atomically. Declare how loading and validation participate in the transaction or how a version check protects a decision made on a prior snapshot.
5. Return success only after commit succeeds. Explicit aborts and unexpected failures leave no committed partial transition or delivery intent.
6. After commit, deliver the fact and notification data through the configured dispatcher/worker. Notification failure does not retroactively undo the invoice; the intent remains retryable under the declared delivery policy.

The event has explicit fields such as invoice ID, organization ID, occurrence time, and the billing-contact snapshot needed by its subscriber. Define the full schema in each implementation. Notification delivery must not mutate the event or look up unrelated business data. Schema fixtures also exercise a typed collection to demonstrate that collections themselves are permitted.

## Required evidence

| ID | Observable guarantee | Core rules |
|---|---|---|
| V01 | Same explicit Domain inputs produce the same decision; Domain has no external effects | R04 |
| V02 | Authorized, open, in-time invoice is voided and exactly one delivery intent is committed | R09, R12–R14 |
| V03 | Missing grant, wrong target scope, invalid state, expiry, and absence have explicit outcomes with no transition or intent | R12, R14 |
| V04 | Repeating a completed void creates no additional intent | R10, R13 |
| V05 | Injected expected abort after a tentative write rolls back that write; unexpected failure does too | R12–R13 |
| V06 | State write or outbox write failure leaves neither partially committed; commit failure is not reported as success | R13 |
| V07 | Dispatch cannot observe uncommitted intents; failure after commit leaves durable retry work | R13 |
| V08 | Concurrent settlement and void cannot both succeed in a way that violates the fixture policy | R04, R13 |
| V09 | Concurrent invocations cannot leak actor or transaction state | R07, R14 |
| V10 | Notification adapter receives sufficient data and does not fetch other modules' data | R10–R11 |
| V11 | Typed collections pass schema checks; arbitrary property bags and malformed external payloads fail | R10 |

V05 may use a transaction-contract test harness to exercise an abort after a write; the invoice use case need not invent a business step solely for the test. Actual database participation, rollback, isolation, and outbox visibility require integration tests, not only fakes. Delivery retry tests must acknowledge possible duplicate external delivery; one committed intent is not a claim of exactly-once email.

## Structural fixture pairs

For each selected checker, demonstrate that valid code passes and the corresponding violation fails for the expected rule:

| Valid | Forbidden | Core rules |
|---|---|---|
| Published Access contract | Billing imports Access internals | R02–R03 |
| Declared module dependency | Undeclared cross-module contract import | R03 |
| Explicit time supplied to Domain | Domain calls even an injected live clock | R04 |
| Shared system clock in Infrastructure | Effectful implementation in Kernel | R05 |
| Explicit module assembly | Business code resolves a global service | R06 |
| Classified root-level function or entry point | Production code outside all classifications | R02 |
| Boundary schema uses defined collections | Boundary uses an unstructured payload escape hatch | R10 |
| One resolved baseline violation removed | A new violation substituted at the same total count | R16 |
