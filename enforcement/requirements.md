# Architecture enforcement

Checks implement the [core specification](../kit/docs/architecture/rules.md); they do not define or override it.

See the [profile support matrix](../kit/docs/architecture/profiles/requirements.md) for implementation status, and the [Go checker](go/development.md) and [SQLite scenario](../examples/go/fixture.md) documentation for coverage.

## Checker acceptance criteria

- Build on the ecosystem's standard tooling. Use the language's standard static-analysis framework; where the language has none, use the most widely adopted analysis tool in its ecosystem. Run checks inside tools that projects already use when those tools accept plugins. Write custom checks only for what existing tools cannot express, and document why.
- Reference stable core rule IDs in diagnostics.
- Document supported language/tool versions, configuration, exact commands, and analysis scope.
- Classify all production code and reject uncovered units without mandating the same physical layout in every language.
- Include permitted and forbidden fixtures for each structural check. Verify that the forbidden fixture fails for the intended rule, not an unrelated build error.
- Exercise the shared [invoice-voiding scenario](../examples/void-invoice.md) for behavioral claims. Do not infer transaction or delivery guarantees from an import check.
- Distinguish compiler guarantees, custom static analysis, runtime tests, and agent-review obligations.
- Compare migration violations by identity and scope, not only count.

Implement checks incrementally. Mark a rule supported only after the corresponding fixtures run successfully; retain explicit gaps for partial coverage.
