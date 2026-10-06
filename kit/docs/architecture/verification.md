# Verification map

Applies [R17](rules.md#r17--verification-and-evidence) when setting up or changing checks. The map lists checks to configure, not checks already installed. Prefer the ecosystem's standard analysis tools and the ones the project already runs; add custom checks only for rules they cannot express. Record the configured commands in [project configuration](project.md#verification), and identify each architecture check's supported tool versions and its permitted/forbidden fixtures.

| Rules | Verification mechanism | Remaining evidence obligation |
|---|---|---|
| R01 | Profile/rule references and project configuration checks | Explain deviations; confirm profiles preserve semantics |
| R02–R03 | Complete source classification, dependency/public-API checks, declared module-dependency checks, production/test separation | Confirm actual responsibilities and meaningful ownership boundaries; declare dependencies wired only through composition |
| R04 | Effect restrictions where supported; domain decision tests with explicit inputs | Inspect indirect calls and hidden effects |
| R05–R08 | Public-signature, import, wiring, and lifetime checks where supported | Identify real consumers, vendor leakage, adapters exposed by composition, ambient state, and concurrency assumptions |
| R09 | Storage permissions, ownership mapping, schema/query checks where supported | Trace string-based storage access and derived-data lineage |
| R10–R11 | Schema/type checks, boundary validation tests, interaction fixtures, contract/retained-payload compatibility tests | Identify affected consumers and retained versions; review rollout/migration, required ordering, cross-module data gathering, and exception scope |
| R12–R13 | Failure-injection integration tests with actual transactional resources, including invalid payloads and permanent delivery failures | Trace transaction handles, nested calls, external effects, retries, blocked-delivery detection/recovery, and concurrency |
| R14 | Scoped authorization and resource-ownership tests | Verify context comes from trusted identity and the actual resource |
| R15 | Read-only access tests and published-data contracts | Verify dependencies, lineage, freshness, and absence of workflow bypasses |
| R16 | Compare individual baseline violations in CI | Explain scoped boundary corrections and changed fingerprints |
| R17 | Positive/negative checker fixtures and recorded check results | Report missing coverage rather than inferring guarantees from passing checks |
