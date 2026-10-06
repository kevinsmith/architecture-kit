# Agent Instructions

Don't commit automatically. Draft the commit message and wait for my confirmation. Commit messages titles should be written in the imperative mood and any body should explain why, not just give details on the changes being made. Keep it succinct.

## Repository purpose

This is the canonical architecture kit, not an application. Optimize for agents writing all project code. Retain rules that protect correctness, limit coupling or change scope, coordinate ownership, or enable verification; remove rules justified solely by human convenience.

- `kit/` contains material copied into adopting projects. Keep it self-contained.
- `kit/docs/architecture/rules.md` is the authoritative core. Language profiles map its rule IDs to idiomatic implementations; checks do not override it.
- `enforcement/` contains checker acceptance criteria.
- `examples/` contains the shared reference scenario.

Keep this maintenance file separate from `kit/AGENTS.md`. Do not invent application paths or build commands. When changing a rule, check the profiles, companions, reference scenario, enforcement claims, and README dependency diagram (`docs/modular-monolith-dependency-diagram.svg`) for consistency. Distinguish proposed mechanisms from verified checks.
