# Identity and access

Applies [R03, R07, R09, R11, and R14](rules.md) to identity-related boundaries. Names and signatures below are examples, not universal APIs.

## Ownership

Identity lifecycle, authentication, and access grants are distinct responsibilities. They may belong to one coarse module or separate modules when ownership and change coupling justify it. Authentication is not assumed to be unchanging infrastructure.

Keep capability-specific data with its owning module: Billing owns its payer records, not extra billing fields in a central identity dataset. Shared identifiers connect those records without importing another module's private model. Do not invent principal kinds or module splits before they are needed.

## Three separate checks

For “void an unsettled invoice belonging to an organization where this actor has permission”:

1. **Permission grant:** does this authenticated actor have permission to void invoices in the relevant scope? A possible contract is `can(actor, permission, organization)`; other systems need different scope or no scope.
2. **Resource ownership:** does the invoice actually belong to that authorized scope? The owning module establishes this from trusted resource data, not just a caller-supplied organization ID.
3. **Business validity:** is the invoice in a state where voiding is legal? Billing's pure Domain evaluates that rule using explicit inputs.

The workflow must satisfy all three. Permission does not prove resource ownership; ownership does not prove permission; neither makes an invalid business transition valid. Keep resource-state business rules with their owner rather than rebuilding them in a central access module.

An access capability may read its own grant data. If it needs facts owned elsewhere, the workflow supplies those facts or records the narrow exception required by R11. Choose the consistency strategy needed for the permission and resource checks; do not assume a previously read grant remains valid indefinitely.

## Invocation context

Authentication adapters establish identity from the transport's credentials. Application receives a trusted actor value or an explicitly scoped identity dependency. Domain receives only the identity-related values needed for its decisions.

Do not expose a generic shared session bag or store the current actor in a process-wide singleton. Jobs and message consumers need an explicit identity/delegation policy as well as HTTP handlers. Concurrent or long-lived execution must not leak actor state between invocations.

Replacing sessions with tokens can preserve a use-case contract, but changes to expiration, revocation, or delegation semantics may require workflow changes. It is not automatically a one-binding substitution.

## Evidence

Test a denied actor, a permitted actor targeting the wrong scope, and an authorized actor attempting an invalid business transition. Verify invocation isolation where services are reused. Trace how identity and scope are established; a passing type check does not authenticate a value.
