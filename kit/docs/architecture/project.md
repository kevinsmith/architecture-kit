# Project architecture configuration

Complete this file during adoption. `UNSET` means unresolved, not an approved exception. Keep paths and commands relative to the declared application root. Preserve project-owned entries when updating the kit.

## Installation

| Setting | Project value |
|---|---|
| Adopted kit revision or release | UNSET |
| Application root(s) | UNSET |
| Languages and runtime/toolchain versions | UNSET |
| Selected language profile(s) | UNSET |
| Entrypoints and execution model | UNSET |
| Location of architectural decisions | UNSET |

## Source classification and ownership

Map all production code to the roles in R02. Include shared contracts, shared Infrastructure, composition, generated source, and root-level functions. Record how the checker detects uncovered code. Do not use a catch-all role to exempt unknown code. Record each module's dependencies on other modules (R03).

| Module or shared role | Source/package/symbol selectors | Owned datasets/namespaces | Published contracts | Depends on (modules) | Classification check |
|---|---|---|---|---|---|
| UNSET | UNSET | UNSET | UNSET | UNSET | UNSET |

## Operational contracts

- **Dependency lifetimes and invocation context:** UNSET
- **Transaction participation, nesting, isolation/concurrency control:** UNSET
- **Event dispatch, delivery, retries, and duplicate handling:** UNSET
- **Authorization scope and trusted context:** UNSET
- **Reporting boundaries, lineage, and freshness:** UNSET

Mark an item inapplicable with a reason when the project does not have that concern.

## Verification

Record each configured check and the rules it covers. Rules without an automated check remain review obligations, as listed in the [verification map](verification.md).

| Command and working directory | Rules covered | CI job |
|---|---|---|
| UNSET | UNSET | UNSET |

**Review-only rules:** UNSET

## Deviations and baseline

**Deviations:** None recorded. This does not establish conformance until configuration and verification are complete.

For each deviation, record the rule ID, exact scope, reason, compensating verification, and condition for revisiting it. R11 query exceptions must also identify the permitted module contracts and why input-supplied data is insufficient.

**Existing-violation baseline and comparison command:** UNSET. Record an empty baseline for a conforming greenfield project, or the reviewed baseline and comparison command governed by [R16](rules.md#r16--bounded-migration).
