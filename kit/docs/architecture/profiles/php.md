# PHP profile

Maps the [core](../rules.md) to PHP, validated on PHP 8.5 with PDO SQLite. Installation and commands: [PHP enforcement](../php-enforcement.md).

## Roles and contracts — R02–R08

- PHP has no module visibility: any class can name any other class. Namespaces are the boundary, and only static analysis enforces it, so every role must be recognizable from its namespace (R02, R05).
- Map roles to namespaces under the application's root namespace: `App\<Module>\Contracts`, `Domain`, `Application`, `Presentation`, and `Infrastructure`; `App\Platform` for Shared Infrastructure; and `App\Bootstrap` for composition. Create a role namespace only when it has code. Reject every symbol outside these namespaces, including global functions and code outside `App\`.
- Publish each module's contracts in `App\<Owner>\Contracts` (R05). A technical contract no module owns goes in `App\Platform\Contracts`; a shared value type no module owns comes from a library, such as `brick/money`, governed as an external dependency (R08).
- A PHP class must name the interface it implements, so a consumer-defined interface would make the provider import the consumer. Publish call contracts with the provider instead. For events, the publisher also publishes the subscription port, such as `InvoiceVoidedSubscriber`; the subscriber implements it and declares its dependency on the publisher (R03, R10).
- Module-internal ports live in the module's Application, typed with its Domain values. Infrastructure implements them; composition binds them.
- Domain uses `final readonly` classes, enums, and methods over explicit inputs. Pass `DateTimeImmutable` values and loaded data instead of a clock, repository, or random source; an injected `ClockInterface` is still effectful (R04). Return the next state with `clone($this, [...])` rather than mutating shared objects.
- Published classes are `final readonly`. Exceptions in contracts extend built-in exceptions, which are not readonly; list them explicitly in the finality rule.
- Prefer PSR interfaces for vendor capabilities, such as `Psr\Clock\ClockInterface`, at Application boundaries. A PSR interface is not automatically a suitable published contract (R08).
- Name built-in functions fully qualified (`\array_map`) or import them with `use function` in roles with reviewed allowlists. An unqualified call resolves through the namespace fallback, and the checker rejects it as unreviewed.

### Composition and lifetimes

- Compose in `App\Bootstrap`, by hand or with a container that only composition sees. Business code never receives `Psr\Container\ContainerInterface` or the composition root (R06).
- Entry scripts in the global namespace, such as `public/index.php` or a worker script, call composition and nothing else.
- Construct `PDO` in composition and pass it to Infrastructure constructors. `PDO` and `PDOStatement` never appear in other roles.
- Shared services hold no invocation state. Long-lived runtimes, such as FrankenPHP or RoadRunner workers, queue consumers, or Fibers, reuse one instance across invocations, so a per-request assumption that holds under PHP-FPM becomes a leak (R07). Pass the authenticated actor to the use case explicitly; never read `$_SESSION` or another superglobal from a shared service.
- A `PDO` connection supports one transaction at a time. Code that interleaves invocations on one connection, such as Fibers, must not suspend inside a transaction, or the invocations share it.

## Outcomes and execution — R07, R10, R12–R14

- Return expected outcomes as a union of small `final readonly` classes or enums, such as `Voided|Denial|Refusal`, and handle them with an exhaustive `match`. Keep Domain refusals as their own type instead of copying them into an Application hierarchy (R12).
- Throw exceptions only for unexpected failures. Never catch `Throwable` to convert it into a business outcome.
- Prefer a unit of work that takes a callable: commit when it returns, roll back and rethrow when it throws. A normal return commits, so an expected abort after a tentative write throws a dedicated exception inside the callable, and the use case converts it to an outcome after the rollback (R12, R13).
- When `COMMIT` fails, some drivers keep the transaction open. SQLite does on a deferred constraint failure. Roll back explicitly so tentative writes cannot reach the connection's next unit of work.
- Perform external effects only after commit, through an outbox when delivery must survive a failure. Dispatching a PSR-14 event inside the callable does not make the listener's effects transactional (R13).
- Take the actor from the trusted driving adapter and the resource's scope from stored data (R14).

### Driving boundaries

Decode untrusted representations with `json_decode(..., JSON_THROW_ON_ERROR)`. Check the exact set of keys, each field's type, and list shape with `array_is_list`, then build typed values whose constructors reject incomplete data. `array`, `mixed`, `object`, and `stdClass` are not schemas; give every published array a `list<T>` or shape type in its docblock (R10). PHP integers are 64-bit, but many JSON consumers read numbers as floats; encode large integers, such as nanosecond timestamps, as decimal strings.

## Verification — R17

Use Mago's guard for dependency and structural rules and its analyzer for types, with the analyzer failing on warnings so that untyped arrays (`imprecise-type`) fail. The reference configuration and its coverage are in [PHP enforcement](../php-enforcement.md).

Test transactions, rollback, commit failure, outbox visibility, and conditional writes against the real database. PHP has no race detector; interleave invocations deterministically, for example by suspending a Fiber between a use case's read and its write, and run competing writers through separate connections.

Record commands and remaining review obligations in [project configuration](../project.md).
