# Language profiles

Profiles map the [core rules](../rules.md) to idiomatic implementations. They are not independent specifications. Read the profiles selected in [project configuration](../project.md); projects using multiple languages must also specify the serialized contracts connecting them.

## Supported profile

| Profile | Validation status |
|---|---|
| [Go](go.md) | Executable scenario and partial checker |

## Profile requirements

Each profile must identify:

- How code maps to roles and how unclassified code is detected (R02).
- How published contracts, explicit composition, and pure Domain decisions are represented (R03–R08).
- How expected outcomes, transaction aborts, invocation state, and concurrency are handled (R07, R12–R14).
- What tooling actually checks, what runtime tests verify, and what remains an agent-review obligation (R17).

All language examples use the same invoice-voiding behavior. Equivalent outcomes, authorization, rollback, and delivery semantics matter; identical classes, packages, or abstractions do not. Compiler restrictions on visibility or ownership do not by themselves prove business ownership, purity, or transaction participation.
