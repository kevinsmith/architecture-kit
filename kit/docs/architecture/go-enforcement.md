# Installing Go enforcement

This is the authoritative installation, configuration, and coverage guide for the supplied Go checker, `archcheck`. It is a `go/analysis` analyzer that runs as a golangci-lint module plugin, for projects that already use golangci-lint, or as a standalone command. Both hosts run the same analyzer with the same configuration. It provides partial enforcement.

## Files and placement

From the selected kit revision:

1. Copy the `enforcement/go` directory into a tooling directory, with the distribution's `LICENSE` and `kit/docs/architecture/COPYRIGHT`. Keep the tests: they establish what the checker supports. The tooling directory is its own Go module; inside the application's tree it is a nested module that the application's `./...` patterns skip.
2. Copy `enforcement/go/architecture.template.json` into the application root as `architecture.json`. Fill in `module`, `packages`, `files`, and `depends_on` with the project mapping; `examples/go/architecture.json` shows a complete one. Keep the reviewed `pure_imports` and `forbidden_symbols` defaults, and add a package only after reviewing its operations (R08).
3. Choose a host:
   - **golangci-lint:** in the tooling directory, run `golangci-lint custom` to build `golangci-lint-archcheck` from the pinned version in `.custom-gcl.yml`. Merge `golangci.example.yml` into the application's `.golangci.yml`, and run the custom binary wherever the project ran golangci-lint.
   - **Standalone:** in the tooling directory, build `./cmd/archcheck`, then run it from the application root.
4. Record the adopted kit revision, tool location, host, application root, supported Go version, exact commands, and coverage gaps in [project.md](project.md). Building the checker requires Go 1.26 or later. The reference toolchain is Go 1.27.1 with golangci-lint v2.14.0; verify other versions before changing that claim.

In a workspace, check every owned application module separately with its own configuration and document cross-module ownership checks.

## Commands

```sh
# Checker tests, from the tooling directory:
go test -race -count=1 ./...

# golangci-lint host: build once per version change from the tooling directory,
# then run from the application root.
golangci-lint custom
/absolute/path/to/architecture-tool/golangci-lint-archcheck run ./...

# Standalone host: build from the tooling directory, then run from the application root.
go build -o /absolute/path/to/bin/archcheck ./cmd/archcheck
/absolute/path/to/bin/archcheck ./...
```

Record the actual paths in project configuration. Run the checker and its tests in CI, plus the application's own type/build, behavioral, and integration checks. A Go compiler and race-detector-compatible C toolchain are required for the tests.

The standalone command takes `-config` and `-baseline`; the plugin takes the same names under `settings`. The configuration defaults to `architecture.json`. Relative paths resolve from the root of the Go module being analyzed.

Each run sees only the files of one build configuration. Run the checker under every deployed configuration, setting `GOOS`, `GOARCH`, and build tags as that build does.

With golangci-lint, do not suppress `archcheck` with `//nolint` directives or exclusion rules; existing violations belong in the baseline (R16). golangci-lint hides issues in generated files by default; set `linters.exclusions.generated: disable` when generated code must be checked.

## Configuration contract

- `module` must match the application's `go.mod`.
- `packages` maps exact relative directories to `module` (architectural owner) and `role`: `domain`, `application`, `presentation`, `infrastructure`, `contract`, `shared-infrastructure`, `composition`, or `test`. Missing, empty, and unclassified packages fail. `.` names the root package.
- `files` optionally maps exact production file paths to role overrides inside private module packages. It does not create compiler visibility. References to declarations and methods in the package's other files are checked; indirect effects remain review obligations.
- `public: true` marks a module's published Application API, available to Application/Infrastructure consumers. `effectful: true` marks a contract package containing effectful ports, which Domain may not import. Effectful contract packages may import `context`; an unmarked contract package that imports it fails.
- `pure_imports` lists reviewed external packages for pure-role use and boundary types; published signatures additionally permit `context`. This does not approve all package behavior. `forbidden_symbols` maps import paths to prohibited package-level identifiers in pure roles, resolved by type through aliases, dot imports, and function values. Use the profile's effect guidance and retain checks for effectful operations in otherwise useful packages such as `time`.
- `depends_on` maps each architectural module to the other modules it depends on (R03). Non-composition imports of another module's contract or public packages must be declared. Unknown modules, self-dependencies, and cycles fail. Dependencies wired in composition count too: where composition passes one module's concrete implementation to another module's interface, the consumer depends on the provider. A port whose methods take only its module's published contract values (and a context) and return at most an error is a subscription port, so its implementations depend on the publisher. Shared Infrastructure adapters add no module edge.
- `storage_imports` lists persistence packages, such as `database/sql`, that Application and Presentation may not import; an entry also covers its subpackages.
- `datasets` maps modules to the tables they own (R09). Each table needs exactly one owner. When set, constant SQL passed to functions and methods of `storage_imports` packages may name only tables its own module owns; unowned tables fail. A reporting adapter that reads another module's tables (R15) needs a recorded deviation, with its findings baselined.
- `transaction_functions` names the functions or methods that run a transaction callback, and `external_effects` names functions, methods, or whole types whose calls perform external effects, each as `import/path.Name` or `import/path.Type.Method` (R13). Inside a callback literal passed to a transaction function, goroutines and calls to an external effect fail.
- `forbid_init` opts into rejecting owned `init` functions. Standard driver registration at composition remains permitted; inward service location does not.

`test` identifies helper packages; production imports of them fail. Do not classify runtime code as test or composition to bypass its responsibilities. Configuration errors stop the run rather than producing diagnostics.

## Coverage and limits

| Rules | Demonstrated checks | Remaining obligations |
|---|---|---|
| R02–R03 | Complete package/file classification, stale entries, role/module imports, declared module dependencies and an acyclic declared graph, dependencies injected in composition, test dependencies, references across file roles including methods, adapter use of their own Application and Domain, storage packages outside Infrastructure | Actual responsibilities; Domain decisions made by adapters through Domain methods or, in Infrastructure, Domain functions; dependencies wired through interface-typed variables, factories, or containers; per-declaration roles and Domain entity/value distinctions |
| R04–R05 | Reviewed imports, configured effectful identifiers, effectful-port imports, Domain writes to package-level state | Calls through interfaces or function values, including local unmarked interfaces; reads of shared state written elsewhere; unlisted effectful APIs |
| R05 | Types and generic constraints of published functions, methods, types, variables, and constants, followed through owned named types and aliases | External dependency definitions |
| R06 | Inward imports of composition, disallowed blank imports other than `embed`, opted-in `init` prohibition | Other service locators and hidden initialization |
| R09 | Tables named after `FROM`, `JOIN`, `INTO`, `UPDATE`, `TABLE`, `TRUNCATE`, and `REFERENCES` in constant SQL passed to storage packages, checked against `datasets` | Dynamic SQL, ORMs, unusual syntax, other namespaces such as cache keys, files, and queues, and storage permissions |
| R10 | `any`, empty interfaces, `json.RawMessage` (`jsontext.Value`), and protobuf `Any`/`Struct` values in published surfaces, including owned aliases and collections | External/generic schemas, opaque byte encodings, protobuf envelopes, runtime validation, contract evolution and retained-payload compatibility |
| R13 | Goroutines and configured external effects inside callbacks passed to configured transaction functions | Effects reached through helper functions or callbacks defined elsewhere, unlisted effects, commit and rollback semantics, delivery policy |
| R16 | Baseline identity, multiplicity, and stale records | Justification and semantic expansion with unchanged references |

The analyzer type-checks each package in the run's build configuration, including generated source present at analysis time, and skips `_test.go` files and test packages. Stale-classification checks scan every file regardless of build configuration.

File-role checks follow type-resolved references to package-level declarations and methods in other files of the same package, not closure effects. They do not create compiler encapsulation. Opaque schema exceptions require bounded decisions and corresponding checker changes with fixtures, not a blanket waiver.

Adapters' use of their own module follows R02's surface limits. Presentation may use the Domain types reachable from the exported surface of the Application packages it imports, with their fields and methods, constants of those types, and Domain error types and values, but no Domain functions. Infrastructure may use its module's Domain, including constructors and accessors that rebuild and read encapsulated entities, and its Application's types and values, but may not call Application functions or methods. Calls through interfaces are not restricted, because they reach whatever composition supplies.

Tests type-check valid and violating fixtures before checking their diagnostics, and record known indirect-effect gaps. Passing checks does not establish purity, SQL ownership, transaction correctness, authorization, or runtime schema validity.

## Existing violations

Baseline policy is defined by [R16](rules.md#r16--bounded-migration). The baseline is a JSON array containing `rule`, `file`, `symbol`, `target`, and `message`. The checker matches records by rule + relative file + declaration + target, with occurrence counts; messages and line numbers are not identity. Unmatched current occurrences and stale baseline occurrences are reported, and an entry for a file that no longer exists stops the run. The checker does not generate baselines automatically.

## Adoption evidence

Before declaring installation complete, demonstrate that the configured host accepts a valid boundary and rejects a deliberately invalid one with the expected rule ID. Verify that all actual source is covered in every deployed build configuration. Retain project-specific fixtures as the layout and contracts evolve.
