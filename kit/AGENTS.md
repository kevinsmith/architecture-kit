# Architecture instructions

Read `docs/architecture/project.md` for this project's profiles, source classification, ownership, verification commands, and recorded deviations. If required information is unset, resolve it from the project or ask; do not invent commands or claim enforcement is installed.

The authoritative rules are in `docs/architecture/rules.md`. Profiles map them to language idioms. Checkers implement them; a conflict is a defect to resolve, not permission to weaken a rule.

Rules: R01 authority · R02 roles and classification · R03 module boundaries · R04 Domain purity · R05 shared contracts · R06 composition · R07 lifetimes · R08 external dependencies · R09 data ownership · R10 calls and events · R11 shared capabilities · R12 outcomes · R13 transactions and effects · R14 authorization · R15 reporting · R16 migration · R17 evidence.

For each change:

1. Put a use case in the module whose data and invariants it changes. If it changes several modules' data, put it in the module that owns the workflow's outcome and change the others through their contracts. Add a module only for data with its own lifecycle and vocabulary that no existing module owns (R03, R09).
2. Identify the affected module, logical roles, owned data, and public contracts. Ensure new production code is covered by source classification in this change (R02–R03, R09).
3. Keep Domain decisions free of external effects and hidden time or randomness. Application supplies values and coordinates effects through contracts (R04).
4. Keep cross-module interactions explicit and within the declared module dependencies. Required work uses direct contracts; events announce facts. Shared capabilities receive data rather than gathering unrelated business knowledge (R03, R10–R11).
5. For authorization, transactions, events, or shared services, verify scope, transaction participation, delivery semantics, and dependency lifetimes (R07, R12–R14).
6. Run the configured checks and report each command and its result. If the change adds, splits, or merges a module; changes a module dependency, published contract, or event schema; changes transaction, delivery, or authorization behavior; or changes classification, the baseline, or a deviation, also report evidence for each affected rule and state what remains unchecked (R17).

   Report evidence as: `Rule ID | Implementation location | Check/test result | Remaining unchecked gap`. Rules sharing evidence may be grouped.

Read only the relevant companions:

- `docs/architecture/greenfield.md` — new application setup.
- `docs/architecture/migration.md` — existing code, baselines, or boundary corrections.
- `docs/architecture/identity-and-access.md` — identity, authentication, authorization.
- `docs/architecture/reporting.md` — cross-module reporting and analytical data.
- `docs/architecture/verification.md` — setting up or changing checks.
- Selected profiles listed in `docs/architecture/project.md` — language mappings and verification limits.

Do not restructure unrelated code, silently broaden a deviation, or regenerate a baseline to accept new violations (R16–R17).

Do not label code as test, composition, or another role merely to evade its actual responsibilities (R02).
