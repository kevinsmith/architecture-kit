# Migrating an existing codebase

The [core](rules.md) defines the destination. R16 permits a task-required boundary correction but prohibits unrelated restructuring during feature or bug work. Record the correction's scope and verify affected behavior; schedule broader migration separately.

## Procedure

1. **Inventory before moving code.** Identify entry points, source roots, data owners, public interactions, actual transaction boundaries, ambient state, and hidden coupling through SQL, cache/session keys, files, configuration, or dynamic calls.
2. **Configure classification and checks.** Select language profiles and map every production code unit to a role. Choose checks from the [verification map](verification.md) and record actual commands and coverage gaps in [project.md](project.md). Discovery must account for root-level code and non-class constructs, not just familiar directories.
3. **Baseline specific existing violations.** Identify each by rule, offending dependency or operation, and stable source identity. Record scope and reason. Tool-specific baselines may need a wrapper to compare individual violations rather than totals.
4. **Establish explicit composition.** Expose dependency selection and lifetimes before changing behavior. Separate invocation state from shared services.
5. **Carve one justified boundary at a time.** Required work uses a published call contract; facts use schema-defined events; shared data uses explicit boundary types. A dependency may instead reveal misplaced cohesion. Do not turn every dependency into an event.
6. **Verify the affected guarantees.** Check business behavior, data ownership, authorization, transaction participation, and external effects as relevant. Namespace changes alone do not establish conformance.
7. **Ratchet by identity.** Run the configured baseline comparison in CI and maintain the baseline according to [R16](rules.md#r16--bounded-migration).

Baselines are migration debt, not general exemptions for a file, module, or rule. Avoid regenerating them in a way that silently accepts new debt. Prioritize debt that blocks safe changes; do not mix broad conformance work into an unrelated task.
