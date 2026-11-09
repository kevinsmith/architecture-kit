# Installing PHP enforcement

PHP enforcement uses [Mago](https://mago.carthage.software), a PHP toolchain with an architectural guard and a static analyzer, configured in `mago.toml`. The guard expresses namespace dependency rules, restrictions, and structural rules, and the analyzer checks types. The supplied [architecture extension](#architecture-extension), a Mago analyzer extension, checks what neither can express, such as SQL table ownership and effects inside transactions. Together they provide partial enforcement. Language decisions are in the [PHP profile](profiles/php.md).

## Installation

1. Require Mago as a pinned development dependency, so `composer.lock` fixes the version: `composer require --dev "carthage-software/mago:1.59.0"`. The first run downloads the matching binary; on shared CI runners, set `GITHUB_TOKEN` for that run to avoid GitHub's anonymous rate limit.
2. Write `mago.toml` at the application root with the sections below. The kit's reference fixture, `examples/php/mago.toml` in the kit repository, is a complete mapping for three modules.
3. Install the [architecture extension](#architecture-extension) from the same kit revision:
   - Copy the `enforcement/php/extension` directory into a tooling directory, such as `tools/architecture-check`, with the distribution's `LICENSE` and `kit/docs/architecture/COPYRIGHT`. Keep the tests: they establish what the extension supports.
   - Add a Composer path repository for it, `{"type": "path", "url": "tools/architecture-check"}`, and run `composer require --dev "example/architecture-check:1.0.0"`.
   - Copy its `architecture.template.json` into the application root as `architecture.json` and fill in the mapping; `examples/php/architecture.json` shows a complete one. Keep the reviewed `external_effects` defaults and add the project's own effect classes.
   - Register the worker in `mago.toml`: `[extension-hosts.architecture]` with `command = ["php", "vendor/bin/architecture-worker"]`.
4. Record the adopted kit revision, tool location, Mago version, application root, PHP version, exact commands, and coverage gaps in [project.md](project.md). The reference toolchain is PHP 8.5.12 with Mago 1.59.0; verify other versions before changing that claim.

## Commands

```sh
vendor/bin/mago guard
vendor/bin/mago analyze
```

Run both in CI, plus the application's behavioral and integration tests. `mago analyze` runs the architecture extension; never pass `--no-extensions` in CI. Run the extension's tests from its tooling directory after `composer install` there: `vendor/bin/mago analyze` and `vendor/bin/phpunit`. With existing-violation baselines, add `--baseline guard-baseline.toml --fail-on-out-of-sync-baseline` to the guard and `--baseline analyzer-baseline.toml --fail-on-out-of-sync-baseline` to the analyzer.

## Configuration contract

Every rule's `reason` names the core rule it implements, so each diagnostic carries the rule ID (R17).

- **Sources.** `[source] paths` lists the production roots, such as `["src"]`; `includes = ["vendor"]` lets the analyzer resolve vendor types without analyzing them. Keep tests out of `paths`.
- **Classification (R02).** One `[[guard.structural.rules]]` entry with `on = "**"`, `must-be = []`, and `not-on` listing every classified namespace rejects any other symbol, including global functions and code outside `App\`.
- **Role permits (R02–R05).** One `[[guard.perimeter.rules]]` entry per module and role, listing what that role may depend on. The guard denies by default: a namespace with no matching rule may depend on nothing. Permit other modules' `Contracts` only for declared dependencies. Bootstrap permits `@all`.
- **Pure roles (R04, R05, R08).** Domain and Contracts permit their own class-likes and a reviewed `pure` layer of built-in classes, functions, and constants, never `@native`. Restrict own-namespace permits to `kinds = ["class-like", "constant"]`, so an unqualified call that falls back to a built-in is rejected instead of matching the namespace.
- **Restrictions.** `[[guard.perimeter.restrictions]]` run before permits, so no permit can override them:
  - Declared module graph (R03): in dependency order, deny each module from the modules before it.
  - Composition (R06): `App\Bootstrap\**` and `Psr\Container\**` are allowed only from Bootstrap; global entry scripts may also reach Bootstrap. A `@global` perimeter rule permits entry scripts only Bootstrap and built-ins.
  - Storage (R02): every database API the project can load is allowed only from Infrastructure and Bootstrap. The pattern names APIs, not the capability, so list each one, such as `{PDO**,Pdo\**,SQLite3**}` for PDO, its driver subclasses like `Pdo\Sqlite`, and the `sqlite3` extension, plus `mysqli`, `pg_*`, or vendor clients the project installs.
  - Tests (R02): `Tests\**` is denied from all production code.
- **Published values (R05).** A structural rule makes classes in `App\*\Contracts\**` `final` and `readonly`.
- **Schemas (R10).** `[analyzer] check-missing-type-hints = true` and `minimum-fail-level = "warning"` make untyped parameters, properties, and returns fail, and `imprecise-type` reject a bare `array` or `iterable`.

## Architecture extension

The guard checks only dependencies written as symbols and has no extension API, so these rules need custom checks. Mago's analyzer accepts extensions: worker processes, written with the PHP SDK bundled in `carthage-software/mago`, that receive each analyzed file's syntax, resolved names, and inferred types, the codebase metadata, and the project's symbol-reference graph. The supplied extension is one. Its issues are ordinary analyzer issues, so `mago analyze` reports them and the analyzer baseline covers them. The extension API shipped in Mago 1.47; the extension requires Mago `~1.59.0`, and its tests must pass before the project changes Mago's version.

`architecture.json` keys, all relative to the application:

- `root` is the namespace holding all production code. `modules` lists the business modules, each a namespace directly under `root` with role segments `Contracts`, `Domain`, `Application`, `Presentation`, and `Infrastructure`. `shared` and `composition` name the Shared Infrastructure and composition namespaces under `root`. These must agree with the guard's classification.
- `datasets` maps modules to the tables they own (R09). Each table needs exactly one owner.
- `transaction_methods` lists `Class::method` entries whose closure arguments run inside one transaction; calls on implementations match too (R13).
- `external_effects` lists class-likes and functions whose calls perform external effects (R13).
- `external_consumers` maps a published class-like to the reviewed reason its consumer is outside the analyzed code (R05). Record a reason, not a waiver.

Unknown keys, duplicate names, and a table with two owners stop analysis with an error. The worker reads `architecture.json` from the configuration's directory; pass another path as an extra `command` argument. Workers are trusted local processes that run with the analyzer's permissions.

Checks, each with permitted and forbidden fixtures in the extension's tests. Mago prefixes each issue code with the plugin identifier, such as `architecture/table-ownership`.

| Rules | Check | Issue code |
|---|---|---|
| R09 | Tables named in every string expression in a module's code, following concatenation, interpolation, heredocs, and class constants, belong to that module's declared datasets; undeclared tables and table names computed at runtime fail | `table-ownership`, `unowned-table`, `computed-table` |
| R13 | Work passed to a configured transaction method, matched by receiver type including implementations, is an inline closure that calls no configured effect class or function | `transaction-effect`, `transaction-callback` |
| R05, R10 | Public signatures of published contracts, including docblock types and collection elements, name no unpublished application type and no `mixed`, `object`, or `stdClass` | `private-type-published`, `unstructured-published-type` |
| R05 | Every published class-like is referenced from another module other than composition, directly or through a consumed contract of its owner; a reviewed exemption records why a consumer is outside the analyzed code | `unconsumed-contract` |
| R04, R05 | Domain and contracts construct no date from the current time: no constructor without a fixed time, no `now()`-style factory, no `createFromFormat` without `!` or `\|` | `ambient-clock` |
| R04–R07 | No static properties, static variables, `global`, or `$GLOBALS` in classified code | `static-state` |
| R07 | Application and Presentation instance properties, including promoted ones, are `readonly`, and their declared and docblock types, including collection elements, hold no known mutable container: `ArrayAccess`, `ArrayObject`, `ArrayIterator`, the `Spl` data structures, `WeakMap`, `stdClass`, or `Ds\Collection`, including subclasses | `writable-property`, `mutable-container` |
| R06, R02 | Superglobals and process configuration only in driving adapters and composition; `include` and `require` only in composition; no `eval` | `ambient-input`, `hidden-initialization`, `eval` |
| R03 | Outside composition, no string literal names an application class-like or function, and no class or function is chosen from a runtime value | `string-dependency`, `dynamic-dependency` |

## Coverage and limits

| Rules | Demonstrated checks | Remaining obligations |
|---|---|---|
| R02–R03 | Complete symbol classification by namespace, role permits, storage, container, and test-code restrictions, declared module order; application symbols named in strings and classes or functions chosen at runtime | Actual responsibilities; names assembled from parts, callable arrays, reflection, and container service IDs |
| R04 | Domain dependencies limited to its own class-likes and the reviewed built-ins, including unqualified calls; clock reads through approved date classes; static and global state | Other effectful operations of approved classes; calls through closures or interfaces received as values |
| R05 | Contract dependencies limited to contracts and pure built-ins; published classes `final readonly`; published signatures, including docblock and collection types, free of unpublished types; a consumer outside the owner | Consumers outside the analyzed code, recorded as reviewed exemptions |
| R06 | Nothing but entry scripts depends on composition; only composition sees a container; request and process input only in driving adapters and composition; file inclusion only in composition; static state in composition | Initialization through framework hooks or Composer `files` autoloading |
| R07 | Static properties, static variables, and globals in shared services; writable properties and known mutable containers in Application and Presentation | State held inside other objects, such as a port implementation or an application class with setters; Infrastructure state's safety under concurrent use |
| R09 | Listed database APIs only in Infrastructure; tables named in SQL strings checked against declared datasets, with computed table names rejected | Database APIs the restriction does not list; SQL built by query builders or ORMs, unusual syntax, storage permissions; cache keys, files, and queues |
| R10 | Missing and imprecise types in published signatures; `mixed`, `object`, and `stdClass` anywhere in them | Runtime validation, docblock types that disagree with runtime values, and retained-payload compatibility |
| R13 | Configured effects inside inline closures passed to configured transaction methods; transaction work defined elsewhere | Effects reached through methods the closure calls, unlisted effects, commit and rollback semantics, delivery policy |
| R16 | Guard and analyzer baseline entries by file, issue code, and message naming the dependency or table, with occurrence counts; expansion, substitution, and stale entries fail with `--fail-on-out-of-sync-baseline` | The declaring symbol is not part of the identity, so a moved violation within one file keeps its entry |

The guard and the extension read code statically. Reflection, names assembled at runtime, and code reached through `include` in composition are outside their view. Passing checks does not establish purity, SQL ownership, transaction correctness, authorization, or runtime schema validity.

## Existing violations

Baseline policy is defined by [R16](rules.md#r16--bounded-migration). Generate each baseline once, review it, and never regenerate it to accept new violations; remove resolved entries with `--remove-outdated-baseline-entries`. Mago's default `loose` baseline matches by file, issue code, and message, with counts; the message names the forbidden dependency.

## Adoption evidence

Before declaring installation complete, demonstrate that the guard accepts a valid boundary and rejects, with the expected rule ID, one deliberately invalid case per configured rule: another module's internals, an undeclared module dependency, an effect in Domain, unclassified code, storage outside Infrastructure, composition used from business code, and one case for each extension check. Show also that forms next to each extension check pass, such as a fixed date in Domain or a common table expression over the module's own tables. Retain these fixtures as the layout and contracts evolve.
