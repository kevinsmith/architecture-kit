# Go profile

Maps the [core](../rules.md) to Go, validated on Go 1.27.1 with SQLite. Installation and commands: [Go enforcement](../go-enforcement.md).

## Roles and contracts — R02–R08

- An architectural module is not necessarily a Go module; one `go.mod` can hold many capabilities. Classify every production package, file, and relevant symbol, including `main`, generated code, and deployed build-tag variants.
- Prefer small consumer-defined interfaces when abstraction is needed. A provider satisfies them without importing the consumer; the consumer still depends on the provider's module, so declare that dependency (R03); the checker verifies it where composition wires a concrete provider into the interface. Published function APIs are also contracts; give their boundary types an explicit public home that does not expose provider internals.
- Domain uses plain structs, methods, and functions. Pass `time.Time` and loaded data instead of calling clocks, repositories, or randomness sources. Do not add an interface per struct. Map iteration order is randomized; sort keys before any order-dependent Domain decision.
- Passing a struct by value does not copy the slices, maps, or pointers it holds. Copy shared values or document who owns them (R05).
- Shared value types belong in a contract package; small pure helpers stay local or use the standard library; a reusable technical library becomes its own Go module, governed as an external dependency; effectful technical code is Shared Infrastructure (R05, R08).

### Visibility, composition, and ownership

- Visibility is package-wide, so roles colocated in one package can reach each other; file-role checks do not create encapsulation. Use separate packages when compiler isolation matters.
- `billing/internal/invoice` is importable only within `billing`; a repository-wide `internal/` does not isolate sibling modules. Put assembly that needs a module's internals inside that module's tree and expose an assembly function. Do not export internals for central wiring.
- Put interface assertions such as `var _ consumer.Port = Provider{}` in composition or consumer tests. A provider that imports the consumer for an assertion recreates the coupling the interface removed.
- Published signatures use builtins, approved value and execution-context types, and intentionally published types. Exporting a function does not make it acceptable to leak a sibling's Domain or persistence model.
- Keep database handles inside adapters after assembly. `*sql.DB` is a concurrency-safe pool; `*sql.Tx` and a reserved `*sql.Conn` belong to one unit of work, never to long-lived service fields.
- Schema definitions and migrations belong to the data owner's Infrastructure; a shared runner may execute them. Generated `sqlc` or ORM models stay in Infrastructure, and `*sql.Row` or ORM sessions never cross Application APIs.
- Compose in `main` or assembly functions, by hand, with generated wiring, or with a container that business code never resolves from. `init`, `sync.Once`, package singletons, and default logger configuration must not hide dependency selection or hold invocation state.
- Blank-import driver registration is permitted in composition and adapters; call `sql.Open` at the configured resource boundary.

## Outcomes and execution — R07, R10, R12–R14

- Represent expected rejections with sentinel or typed errors classified by `errors.Is`/`errors.As`. Wrap with `%w` to preserve identity. Keep operational failures distinct without a parallel outcome hierarchy.
- Prefer transaction callbacks returning `error`: commit on nil, roll back on non-nil, and return commit failures. Expected rejections use the same abort path, so transaction code never inspects business results. A project that returns rejections as values must translate them into an abort explicitly.
- Pass transaction-bound repository operations or a scoped unit of work. `context.Context` does not make operations share a transaction.
- Never use `panic` for an expected rejection, and never recover an unexpected panic into a rejection.
- Pass `context.Context` for cancellation, deadlines, and request metadata, not to resolve stores or transactions through `context.WithValue`. Middleware may put authenticated identity in the context; the adapter extracts it and passes the actor to the use case explicitly. Domain never reads the context.
- Goroutines and channels are neither durable nor transactional, even when started inside a transaction callback. Deliver post-commit effects through the outbox or after the callback returns. Competing outbox workers need a claim/lease or duplicate-handling contract.
- Long-lived handlers and goroutines must not share unsynchronized invocation state.

### Driving boundaries

`main` or `cmd/<name>` assembles the application; adapters live elsewhere. HTTP handlers pass `r.Context()`, establish trusted identity, invoke a use case, and map known errors to responses with `errors.Is`/`errors.As`; any other error is an operational failure.

Validate external representations before trusting them. A strict JSON boundary rejects unknown fields, accepts exactly one value, and checks required fields; any looser policy must be explicit. `any`, empty-interface aliases, `json.RawMessage`, and protobuf `Any` are not schemas; an opaque envelope needs a documented discriminator, schema, and validation path. Published time fields state their epoch, unit, precision, and encoding.

## Verification — R17

Add race-detector tests for shared services and real-database tests for rollback, concurrent transitions, and outbox behavior.

Configure pure roles with reviewed package allowlists, starting from the checker template's defaults. Do not admit effectful packages such as `os`, `net`, `net/http`, `database/sql`, `log`, `log/slog`, or `crypto/rand`, or nondeterministic `math/rand` functions. When allowing `time` for values, forbid `Now`, `Since`, `Until`, `Sleep`, timers and tickers, `LoadLocation`, and `Local`. A deterministic PRNG with explicit state may be reviewed separately.

With `go.work`, check every owned module with its own configuration and declare cross-module workspace dependencies; the supplied checker handles one module at a time. CI must build each deployed workspace and build-tag configuration and run the checker under each.

Use standard linters for rules they already check: `errorlint` for error identity and wrapping (R12); `go vet`'s `copylocks` check and the `contextcheck` and `noctx` linters for shared state and contexts (R07); and `rowserrcheck` and `sqlclosecheck` for database resource lifetimes (R07).

Record commands and remaining review obligations in [project configuration](../project.md). The [checker coverage table](../go-enforcement.md#coverage-and-limits) lists what is checked and what is not.
