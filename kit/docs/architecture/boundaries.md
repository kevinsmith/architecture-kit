# Module boundaries

Applies [R03, R05, and R09–R11](rules.md) when adding, splitting, merging, or reviewing modules, and when fixing a boundary violation. The tests and fixes below are defaults and examples; they add no requirements to the core. Module names are examples, not required modules.

## What a module owns

A module owns a lifecycle: its data, the vocabulary for it, and the rules about it. Name a module for that lifecycle (Orders, Billing, Catalog), not for a moment in it; events name moments (`OrderPlaced`, `InvoiceVoided`). A module named Checkout, Registration, or Signup is a smell. Whoever creates an order also owns its amendment, cancellation, and status transitions, so checkout is only the creation step of Orders. A pre-order journey with its own owner and change pattern can later become a separate module that calls an Orders contract to place the order; Orders still owns the order data.

Business and capability modules have the same structure. When the kind is unclear, ask whose rules the module holds. Rules stated by a business owner or someone in product, such as eligibility, trial terms, or approval flows, belong to a business module. Rules an engineer states, such as password hashing, token rotation, or delivery retries, belong to a capability module, which receives business data rather than gathering it (R11).

## Example: a capability module with its own data

Tax calculates tax for Orders and Billing. Its rates, exemptions, and rounding policy change for their own reasons and need their own tests, so Tax is a module rather than a shared helper (R05). Whether it counts as a business or a capability module does not change its structure.

| Piece | Role | Why |
|---|---|---|
| Calculation contract: line items, jurisdiction, and transaction date in; tax breakdown out | Published contract | Each caller fills every input from data it already owns (R05, R11) |
| Rate selection, exemptions, rounding | Tax Domain | Business decisions over explicit inputs (R04) |
| Calculation use case: loads the rates in effect on the supplied date, applies Domain | Tax Application | Gathers Tax's own data and passes it to Domain (R04) |
| Rate tables and their schema | Tax Infrastructure; data owned by Tax | Reading a capability's own data is not a cross-module query (R09, R11) |
| Rate-store selection and wiring Tax into its callers | Composition | R06 |

Tax never fetches orders, invoices, or customers. If a contract parameter cannot be filled from data the caller owns, the contract is demanding a fetch, and the cross-module gathering has only moved to the callers or to a shared type that mixes several modules' fields.

## Four tests for a proposed boundary

- **Change test.** Things that change together belong together. If typical changes must edit the internals of both sides together, the line runs through one module. Changes that span modules through stable contracts are normal (R03).
- **Data-ownership test.** Every mutable business dataset needs exactly one owner that performs all of its writes (R09). If a split leaves a table, cache namespace, file set, or queue without a nameable owner, the split is wrong or the dataset needs decomposing.
- **Vocabulary test.** One word with two meanings marks a real boundary. "Customer" as a payment-method holder (Billing) and as a ticket opener (Support) are two concepts in two modules, connected only by a published identifier. Two modules that constantly translate between near-identical concepts are probably one module.
- **Interaction test.** Describe the interaction between the two sides. If it fits in a sentence and needs a few contracts ("Notifications reacts when an invoice is voided": one event), the boundary is sound. If it takes a paragraph, needs many contracts that only this pair uses, or creates a cycle in the module graph, it is probably one module.

## Signals that a boundary is wrong

Too fine:

- Changes routinely edit the internals of the same pair of modules together.
- One request crosses the boundary repeatedly (chatty contracts).
- The pair needs many published contracts that no other module uses.
- The declared module graph needs a cycle (R03).

Too coarse:

- Two internal clusters are joined by a thin bridge; the bridge is a seam.
- One concept serves two meanings inside the module and accumulates conditional fields.
- Separate streams of changes consistently touch disjoint halves and collide at the shared edge.

When the signals conflict, stay coarse and split when evidence accumulates (R03). Splitting a coarse module is mostly mechanical; merging fine modules means removing contracts that never needed to exist.

## Relation to domain-driven design

A business module approximates a bounded context, and the vocabulary test is the bounded-context test. There are two differences: capability modules wrap technical machinery rather than a domain model, and published contracts act as a published language between contexts, not a co-owned shared kernel. With those caveats, DDD techniques for finding context boundaries apply to modules.

## Common violations and fixes

| Violation | Fix | Rules |
|---|---|---|
| Billing imports Orders internals | Use a published value (`OrderID`) when only the reference is needed, or a published contract that Orders implements when behavior is needed. Declare Billing's dependency on Orders. | R03, R05 |
| Placing an order must create an invoice | If the order workflow requires the invoice, Orders calls a Billing contract and declares that dependency. If Billing independently reacts to the fact, Orders publishes `OrderPlaced`, and Billing subscribes and declares its dependency on Orders. Never both directions. | R03, R10 |
| Effectful or mutable code in a published contract | Move it to its owning module or Shared Infrastructure; publish only the contract. | R05 |
| Published contract with no consumer outside its provider | Keep it module-local until another module needs it. | R05 |
| Business code resolves dependencies from a container or global registry | Receive the actual dependencies explicitly; composition supplies them. | R06 |
| Application constructs a vendor client or its own Infrastructure implementation | Depend on an application-owned contract; an Infrastructure adapter implements it and composition selects and configures it. | R02, R06, R08 |
| Event identified by a string name or carrying a property bag | Publish a schema-defined event, and validate untrusted representations at their boundary. | R10 |
| Two modules write the same table | Assign one owner; the other changes the data through the owner's contracts. Cross-module reports use a reporting boundary. | R09, R15 |
| Modules share a cache, session, or queue keyspace without partitioning | Composition gives each module a client scoped to its own key prefix or pool, so modules cannot overwrite each other and need not know the backend is shared. Record each module's namespace as an owned dataset. | R06, R09 |
| Domain queries storage or calls an injected repository or clock | Application loads the data and reads the time, then passes the values to Domain. | R04 |
| Shared capability fetches users, invoices, or preferences | The module that owns the workflow gathers the data and passes it in, unless a documented query exception applies. | R11 |
| Expected miss, such as an absent record, reported as an unexpected failure, or an unexpected failure turned into a rejection | Return an explicit expected outcome and keep operational failures distinct. | R12 |
| Outcome carries a user-facing message, or a published signature exposes a private domain or storage model | Carry semantic data that the driving adapter phrases and maps; publish boundary types instead of private models. | R05, R12 |
| Email or another external effect sent inside the transaction or a synchronous listener | Perform it after commit; use an outbox when delivery must survive a failure. | R13 |
| Use case trusts a caller-supplied organization as the resource's scope | Take the scope from the stored resource and authorize against it. | R14 |
