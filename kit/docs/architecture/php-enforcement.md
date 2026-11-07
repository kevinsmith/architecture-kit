# Installing PHP enforcement

PHP enforcement uses [Mago](https://mago.carthage.software), a PHP toolchain with an architectural guard and a static analyzer, configured in `mago.toml`. No custom checker is needed: the guard expresses namespace dependency rules, restrictions, and structural rules, and the analyzer checks types. It provides partial enforcement. Language decisions are in the [PHP profile](profiles/php.md).

## Installation

1. Require Mago as a pinned development dependency, so `composer.lock` fixes the version: `composer require --dev "carthage-software/mago:1.59.0"`. The first run downloads the matching binary; on shared CI runners, set `GITHUB_TOKEN` for that run to avoid GitHub's anonymous rate limit.
2. Write `mago.toml` at the application root with the sections below. The kit's reference fixture, `examples/php/mago.toml` in the kit repository, is a complete mapping for three modules.
3. Record the adopted kit revision, Mago version, application root, PHP version, exact commands, and coverage gaps in [project.md](project.md). The reference toolchain is PHP 8.5.12 with Mago 1.59.0; verify other versions before changing that claim.

## Commands

```sh
vendor/bin/mago guard
vendor/bin/mago analyze
```

Run both in CI, plus the application's behavioral and integration tests. With an existing-violation baseline, run the guard as `vendor/bin/mago guard --baseline guard-baseline.toml --fail-on-out-of-sync-baseline`.

## Configuration contract

Every rule's `reason` names the core rule it implements, so each diagnostic carries the rule ID (R17).

- **Sources.** `[source] paths` lists the production roots, such as `["src"]`; `includes = ["vendor"]` lets the analyzer resolve vendor types without analyzing them. Keep tests out of `paths`.
- **Classification (R02).** One `[[guard.structural.rules]]` entry with `on = "**"`, `must-be = []`, and `not-on` listing every classified namespace rejects any other symbol, including global functions and code outside `App\`.
- **Role permits (R02–R05).** One `[[guard.perimeter.rules]]` entry per module and role, listing what that role may depend on. The guard denies by default: a namespace with no matching rule may depend on nothing. Permit other modules' `Contracts` only for declared dependencies. Bootstrap permits `@all`.
- **Pure roles (R04, R05, R08).** Domain and Contracts permit their own class-likes and a reviewed `pure` layer of built-in classes, functions, and constants, never `@native`. Restrict own-namespace permits to `kinds = ["class-like", "constant"]`, so an unqualified call that falls back to a built-in is rejected instead of matching the namespace.
- **Restrictions.** `[[guard.perimeter.restrictions]]` run before permits, so no permit can override them:
  - Declared module graph (R03): in dependency order, deny each module from the modules before it.
  - Composition (R06): `App\Bootstrap\**` and `Psr\Container\**` are allowed only from Bootstrap; global entry scripts may also reach Bootstrap. A `@global` perimeter rule permits entry scripts only Bootstrap and built-ins.
  - Storage (R02): `PDO**` is allowed only from Infrastructure and Bootstrap.
  - Tests (R02): `Tests\**` is denied from all production code.
- **Published values (R05).** A structural rule makes classes in `App\*\Contracts\**` `final` and `readonly`.
- **Schemas (R10).** `[analyzer] check-missing-type-hints = true` and `minimum-fail-level = "warning"` make untyped parameters, properties, and returns fail, and `imprecise-type` reject a bare `array` or `iterable`.

## Coverage and limits

| Rules | Demonstrated checks | Remaining obligations |
|---|---|---|
| R02–R03 | Complete symbol classification by namespace, role permits, storage, container, and test-code restrictions, declared module order | Actual responsibilities; dependencies through strings, such as service IDs, callable strings, or `class-string` values built at runtime |
| R04 | Domain dependencies limited to its own class-likes and the reviewed built-ins, including unqualified calls | Effectful use of approved classes, such as `new DateTimeImmutable()` reading the clock; calls through closures or interfaces received as values |
| R05 | Contract dependencies limited to contracts and pure built-ins; published classes `final readonly` | Private models in published signatures when the contract package permits them; whether a contract has an external consumer |
| R06 | Nothing but entry scripts depends on composition; only composition sees a container | Hidden initialization in static state or included files |
| R09 | `PDO` only in Infrastructure | Table ownership: SQL is a string the guard cannot read; cache keys, files, and queues |
| R10 | Missing and imprecise types in published signatures | Runtime validation, docblock types the analyzer trusts, and retained-payload compatibility |
| R13 | — | Effects inside transaction callbacks, commit and rollback semantics, delivery policy |
| R16 | Baseline entries by file, issue code, and message naming the dependency, with occurrence counts; expansion, substitution, and stale entries fail with `--fail-on-out-of-sync-baseline` | The declaring symbol is not part of the identity, so a moved violation within one file keeps its entry |

The guard reads code statically. `include`, `eval`, variable class names, and reflection are outside its view. Passing checks does not establish purity, SQL ownership, transaction correctness, authorization, or runtime schema validity.

## Existing violations

Baseline policy is defined by [R16](rules.md#r16--bounded-migration). Generate the baseline once, review it, and never regenerate it to accept new violations; remove resolved entries with `--remove-outdated-baseline-entries`. Mago's default `loose` baseline matches by file, issue code, and message, with counts; the message names the forbidden dependency.

## Adoption evidence

Before declaring installation complete, demonstrate that the guard accepts a valid boundary and rejects, with the expected rule ID, one deliberately invalid case per configured rule: another module's internals, an undeclared module dependency, an effect in Domain, unclassified code, storage outside Infrastructure, and composition used from business code. Retain these fixtures as the layout and contracts evolve.
