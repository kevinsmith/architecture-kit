# Reporting

Applies [R09 and R15](rules.md) to cross-module reporting and analytical data.

## Read boundaries

Give each report one of these read boundaries:

- a published view or query contract from each owning module;
- analytical data published by its owner;
- a derived read model with its own owner and lineage (R09);
- a designated read-only adapter over operational schemas, documented as an exception.

Declare which modules each report depends on and how fresh its data must be. Read-only access prevents writes but not schema coupling: an adapter over operational schemas breaks when the owner changes its schema, so record that dependency where the owner's schema changes will see it.

## Pipelines

Data mesh and medallion pipelines are optional. When used, give each dataset an explicit owner, lineage, and freshness.

## Evidence

Test that reporting adapters cannot write. Verify each report's declared dependencies and freshness, and that no business workflow reads through a reporting path.
