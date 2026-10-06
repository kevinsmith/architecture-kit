# Modular Monolith Architecture

Rules for applications organized around business or capability boundaries, whatever the delivery mechanism: HTTP, CLI, messaging, or graphical interfaces. No paradigm, domain-model style, or directory structure is required.

**Must** is a requirement; **default** and **prefer** allow a reasoned alternative. Examples illustrate rules without adding requirements. Rule IDs are stable.

**Default:** Add a module, layer, interface, event, or shared abstraction only when a rule requires it or a current need justifies it. Structure that protects no guarantee adds cost without benefit.

## R01 — Authority and language idioms

**Must:**
- Treat this core as the authority. [Language profiles](profiles/requirements.md) implement it idiomatically and must not weaken it.
- Record selected profiles, paths, commands, and deviations in [project configuration](project.md).
- Treat a checker/specification conflict as a defect, not an exception.

**Why:** A shared specification prevents language conventions and checker loopholes from becoming conflicting architectures.

## R02 — Logical roles and complete coverage

**Must:**
- Classify every production code unit into a module and role, or a shared or composition role. CI must reject unclassified code. Classify new code in the same change, by its actual responsibility.
- Depend only as permitted below.
- Select concrete adapters only in composition; inward code depends on contracts, never on Infrastructure.
- Keep Presentation and Infrastructure independent of each other, and production code independent of test-only code.
- Preserve these boundaries when roles share a file or package; this may require symbol-level classification.

| Role | Responsibility |
|---|---|
| Domain | Business decisions, invariants, domain values |
| Application | Use cases, authorization coordination, transactions, typed event handlers |
| Presentation (driving adapter) | Decode/validate external representations, invoke use cases, encode outcomes |
| Infrastructure (driven adapter) | Persistence, external effects, vendor integration |
| Published contracts (Kernel) | Cross-module agreements and shared data |
| Shared Infrastructure | Reusable technical adapters, such as a system clock |
| Composition (Bootstrap) | Configure resources, choose implementations, assemble dependencies |

Permitted dependencies, where "own" means the same module:

- **Domain:** own Domain; pure published values and functions.
- **Application:** own Application and Domain; contracts it defines; other modules' published contracts, within declared module dependencies (R03).
- **Presentation:** own Presentation and Application; the values in that Application's boundary signatures, but not Domain decisions.
- **Infrastructure:** own Infrastructure; the contracts and values of its own Application and Domain; published contracts the adapter needs.
- **Published contracts (Kernel):** other published contracts.
- **Shared Infrastructure:** own Shared Infrastructure; published or consumer-defined contracts.
- **Composition:** anything needed to configure and wire components, but no business decisions.

No other dependency on the codebase is permitted. External libraries are governed by R04, R05, and R08.

**Notes:**
- These are source dependencies, not runtime calls: an Application calling an adapter through a contract depends only on the contract.
- Profiles define classification. Physical directories per role and empty scaffolding are not required.

**Why:** Unclassified code and misplaced dependencies let changes bypass the guarantees.

## R03 — Module boundaries

**Must:**
- Give each module a coherent business or technical capability to own.
- Use other modules only through their published contracts, never their internals.
- Declare each module's dependencies on other modules in [project configuration](project.md) and keep the module graph acyclic. A caller depends on the provider of a call, whichever side defines the contract; an event subscriber depends on the publisher, which does not name its subscribers. Break a cycle by merging the modules or by replacing one module's call with an event the other subscribes to, or record it as a deviation.
- Judge boundaries by change coupling. A feature spanning modules is not a defect; repeatedly needing to change another module's internals is a reason to review the boundary or contract.

**Default:**
- Start coarse. Split when distinct data ownership, vocabulary, or change patterns justify a boundary.
- Avoid speculative modules and shared abstractions.

**Notes:**
- A language package, crate, process, or deployment unit is not automatically an architectural module.

**Why:** Explicit ownership and an acyclic module graph contain change. Each module can be built, tested, and reviewed with only the modules it depends on, and concurrent work in different modules does not collide. They also bound what an agent must consider for a change.

## R04 — Strict Domain purity

**Must:**
- Give Domain explicit inputs. Keep it free of I/O, hidden nondeterminism, and ambient or concurrently shared state. An injected effectful interface does not make it pure.
- Obtain persisted data, time, randomness, and identity in Application, and pass the needed values to Domain.
- Put business invariants in Domain. Application coordinates writes; Infrastructure performs them.
- Bypass Domain for a write only when it applies no business decision, such as projecting an established fact. When uncertain, use Domain.

**Default:**
- Queries that make no business decision go from Application to a read adapter without Domain.

**Notes:**
- Domain may mutate state explicitly supplied to it, and a rule may be a function or an entity method. Returning a decision or event is pure; publishing it is an effect.

**Why:** Explicit inputs make decisions reproducible and keep alternate entry points from implementing inconsistent rules.

## R05 — Shared contracts and implementations

**Must:**
- Share a contract only for a demonstrated cross-module need: one provider and one actual external consumer suffice. Keep module-local abstractions local.
- Limit Kernel to published contracts and data, including pure behavior intrinsic to those values. Purity alone does not justify promoting a helper.
- Keep private domain and storage models out of published signatures.
- Keep shared data from becoming a mutable communication channel; profiles define how ownership, copying, or immutability achieve this.
- Put evolving business behavior in its owning module, not a shared utility area.

**Notes:**
- Kernel is a logical role, not a required central package. Consumer-owned interfaces and module-owned public APIs are both valid contracts where idiomatic. A module may implement its published function API in Application; only the published surface becomes the contract.
- One shared technical adapter, such as a system clock, may serve many use cases from Shared Infrastructure.

**Why:** A narrow shared vocabulary limits system-wide coupling without duplicating technical adapters.

## R06 — Explicit composition

**Must:**
- Select implementations, configure resources, and assemble dependencies explicitly in composition code.
- Give business code its dependencies explicitly, never through service locators or hidden initialization.
- Expose entry points such as use cases and workers from composition, not adapters, so callers cannot bypass use cases.
- Classify routes, listeners, and worker registrations as composition, even when located beside a module.
- Keep infrastructure registration from becoming a way for business code to locate services or share invocation state.

**Notes:**
- Assembly may be split into module-specific functions; a container is optional. Language-standard registration, such as a database driver, may occur at the composition/adapter boundary.

**Why:** Explicit assembly keeps every implementation choice and dependency in one place where it can be reviewed and replaced, and keeps behavior from depending on hidden initialization order.

## R07 — Lifetimes and concurrency

**Must:**
- Make dependency lifetimes explicit.
- Keep invocation-specific state, such as the current actor or transaction, out of shared services. Pass it explicitly or use an explicitly scoped instance.
- Make concurrently shared dependencies safe for concurrent use.

**Why:** A request-local assumption becomes a state leak when code runs in a long-lived worker or concurrent runtime.

## R08 — External dependencies

**Must:**
- Keep external dependencies within their role's responsibility. Put an application-owned contract between vendor semantics and business rules or cross-module contracts.
- Approve operations, not just packages: approving a package does not approve every function in it, including in the standard library.

**Notes:**
- Infrastructure may use vendor APIs directly. Pure libraries and value types need no wrapper, and a vendor interface is not automatically a suitable application contract.

**Why:** Abstractions must protect actual boundaries rather than merely rename dependencies.

## R09 — Data ownership

**Must:**
- Give every mutable business dataset one owning module. Other modules read or change it only through the owner's contracts, or an approved reporting path (R15).
- Enforce or verify ownership in shared storage, including tables, cache keys, files, queues, other namespaces, and schema changes. Shared technical storage does not transfer ownership of the data it carries.
- Give derived datasets their own owner and lineage; they never become a second authority for source facts.

**Why:** Storage access is a dependency even when import analysis cannot see it.

## R10 — Cross-module calls and events

**Must:**
- Use direct contracts for required work; use events to announce facts to independent subscribers.
- Put required ordering in an explicit workflow, not listener-registration order.
- Give event fields, including collection elements and map values, explicit schemas, checked by tooling where supported. Arbitrary property bags are not schemas.
- Validate untrusted representations at runtime at their boundary; profiles define how.
- Before changing a published contract, identify its consumers and retained payload versions. Stay compatible with supported consumers and stored data, or coordinate versioning, rollout, and migration.
- Keep retained events decodable and semantically valid for their retention/replay window.

**Notes:**
- Events are not required for every cross-module interaction.

**Why:** Explicit interactions and schemas make dependencies and failure handling verifiable; indirection does not remove runtime coupling.

## R11 — Shared capabilities receive business data

**Must:**
- Gather cross-module data in the Application that owns the workflow, unless a documented query exception applies.
- A query exception records why input-supplied data is insufficient, which module contracts may be queried, and how they serve the capability's own responsibility. Convenience is not a reason; widening the exception requires revisiting the decision.

**Default:**
- Shared capabilities receive business data as inputs and do not query other business modules.

**Notes:**
- Notifications, for example, receives recipient and message data instead of fetching users, invoices, and preferences. Reading a capability's own operational data is not a cross-module query.

**Why:** A shared capability must not accumulate unrelated business knowledge from all of its consumers.

## R12 — Outcomes and failure

**Must:**
- Return expected failures as explicit, idiomatic outcomes, distinguishable from unexpected failures. Never turn an unexpected failure into a business rejection.
- Carry semantic data in outcomes; driving adapters map them to transport representations.
- Preserve error identities. Do not copy a domain error into another outcome hierarchy just to cross a layer.
- Give expected outcomes an explicit transaction-abort path; a normal return is not automatically success. Profiles define how returns, errors, or exceptions map to commit and rollback, independent of business-specific result names.

**Notes:**
- Sentinel or typed errors can represent outcomes; a separate result type is not required.

**Why:** Failure handling must preserve both business meaning and transactional correctness across languages.

## R13 — Transactions and external effects

**Must:**
- Define atomic units in Application and make transaction participation explicit; running synchronously does not make a listener transactional.
- Use one transaction for participating database operations. Document nested use-case behavior; an inner call must not commit independently when the outer workflow promises atomicity.
- Perform external effects, such as sending email, only after the triggering transaction commits.
- When delivery must survive a failure between commit and dispatch, use a transactional outbox: commit the state change and the delivery intent atomically.
- Define the delivery policy: retries, duplicate handling (an outbox does not provide exactly-once delivery), invalid payloads, permanent failures, and how blocked work is detected and recovered without breaking required delivery or ordering guarantees.
- A listener failure rolls back only work in its explicitly shared transaction. Work across independent transactional resources needs an explicit consistency strategy.
- Verify the isolation or concurrency control each invariant needs; a transaction alone does not prevent every race.

**Notes:**
- A read-then-write is safe either with an isolating or locking read in the same transaction, or with a conditional write against the version that was read. An unconditional write of a decision made on a stale read is not.

**Why:** Dependency structure cannot manufacture atomicity across databases, processes, and external systems.

## R14 — Authorization context

**Must:**
- Give authorization the explicit context it needs to evaluate the permission. Take the actor from authenticated identity, and an existing resource's scope from its stored data, not from caller-supplied values.
- Check resource ownership and business invariants in the owning module. A permission grant proves neither that the target is in the authorized scope nor that the transition is valid.

**Notes:**
- No universal authorization signature is required; the scope may be an organization, project, document, account, or the whole system. See [identity and access](identity-and-access.md).
- A permission checked before the write transaction can be revoked before commit; choose and document the freshness the operation needs.

**Why:** Omitting scope or confusing permission with business validity admits unauthorized state changes.

## R15 — Reporting read boundaries

**Must:**
- Read across modules for reporting only through an explicit read boundary that declares its dependencies and freshness. See [reporting](reporting.md).
- Treat direct operational-schema access as a documented exception, enforced read-only.
- Keep business workflows from using reporting paths to bypass module contracts.

**Why:** Reporting needs cross-module data without acquiring unrestricted access to operational internals.

## R16 — Bounded migration

**Must:**
- Keep unrelated architectural restructuring out of feature and bug work. A boundary correction the task requires may be included when its scope is explicit and affected behavior is verified.
- Track existing violations in a baseline by stable identity and occurrence count. CI rejects new or expanded violations, including a substitution that leaves the total unchanged, and resolved entries are removed.
- Treat a baseline as debt, not a pattern to copy. Write new code to the rules even when nearby code violates them.
- When moving code changes a baselined violation's identity, show that it is the same bounded violation before updating its entry.

**Notes:**
- See [migration](migration.md) for establishing a baseline.

**Why:** Bounded changes and violation-specific baselines prevent unrelated behavior changes and debt substitution.

## R17 — Verification and evidence

**Must:**
- For each guarantee, identify how it is verified and what remains unchecked. Run automated checks in CI and give explicit evidence for the rest during review.
- Reference the rule in each architecture check, with fixtures showing permitted and forbidden cases.
- Report the checks run and their results for every change. When a change adds, splits, or merges a module; changes a module dependency, published contract, or event schema; changes transaction, delivery, or authorization behavior; or changes classification, the baseline, or a deviation, also report each affected rule's implementation location, check/test result, and remaining gap.
- Test the guarantee at risk, not folder layouts or implementation details. Guarantees that depend on real infrastructure need tests against it.
- Record deviations with the fields in [project configuration](project.md#deviations-and-baseline). Do not silently extend a deviation or present a deviating project as conformant.

**Notes:**
- The [verification map](verification.md) lists each rule's checks and remaining evidence obligations for setting up or changing checks.

**Why:** Evidence makes verified guarantees and remaining gaps distinguishable.
