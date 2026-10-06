# Greenfield adoption

Apply the [core rules](rules.md). This procedure assumes no particular framework, source layout, database, or build command.

1. **Configure the project.** Complete [project.md](project.md): language/runtime versions, selected profiles, application roots, verification commands, and adopted kit revision. An unset field is not an implicit default.
2. **Identify ownership.** Start with the smallest set of meaningful modules. Record their data and public boundaries. Keep abstractions local until another module needs them (R03, R05, R09).
3. **Map logical roles to code.** Choose an idiomatic layout and classification mechanism that covers all production code, including entry points, functions, generated code, and shared adapters. Classify composition separately even when it is colocated with a module. Reject uncovered code in CI (R02).
4. **Make assembly explicit.** Wire a first use case, its adapters, and any shared technical dependencies. Declare lifetimes and invocation context. No speculative Kernel or empty layers are required (R04–R08).
5. **Specify behavioral guarantees.** For the first workflow, define expected outcomes, authorization scope, transaction participation, external effects, and delivery semantics. Select only infrastructure the workflow actually needs (R10–R14).
6. **Install verification.** Use the selected profiles and the [verification map](verification.md) to choose checks. Configure CI and add permitted/forbidden fixtures for architecture checks. Run meaningful behavioral and integration tests. Record which guarantees remain review obligations (R17).
