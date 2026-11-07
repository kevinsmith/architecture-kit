# PHP architecture checks

PHP enforcement is configuration for [Mago](https://mago.carthage.software), not a custom checker: Mago's guard and analyzer express the rules the [PHP profile](../../kit/docs/architecture/profiles/php.md) needs. Installation, configuration, and coverage are defined in the copyable [PHP enforcement guide](../../kit/docs/architecture/php-enforcement.md).

[`fixtures.py`](fixtures.py) demonstrates that the reference configuration, [`examples/php/mago.toml`](../../examples/php/mago.toml), accepts the reference application and rejects one forbidden case per configured rule, each with the expected rule ID or analyzer code. It also checks baseline identity, expansion, substitution, and stale entries. Its fixtures name the reference modules; an adopting project writes equivalents for its own mapping.

With PHP 8.5, `pdo_sqlite`, and Composer 2 installed, from the repository root:

```sh
(cd examples/php && composer install)
python3 enforcement/php/fixtures.py examples/php
```

`python3 scripts/verify.py` is the source of truth: it runs these checks, documentation, the [SQLite scenario](../../examples/php/fixture.md), and the Go checks in pinned Docker images.
