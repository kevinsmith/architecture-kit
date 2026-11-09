# PHP architecture checks

PHP enforcement is configuration for [Mago](https://mago.carthage.software)'s guard and analyzer, plus `extension/`, a Mago analyzer extension for the rules the guard cannot express. The [PHP profile](../../kit/docs/architecture/profiles/php.md) defines what they check. The extension is a Composer package that projects copy and install through a path repository, configured by an `architecture.json` mapping; [`architecture.template.json`](extension/architecture.template.json) is the starting configuration. Its tests run `mago analyze` with the extension on a copy of a small application in `extension/tests/testdata`, with a forbidden fixture for every check and permitted fixtures next to the forms the checks reject. Installation, configuration, and coverage are defined in the copyable [PHP enforcement guide](../../kit/docs/architecture/php-enforcement.md).

[`fixtures.py`](fixtures.py) demonstrates that the reference configuration, [`examples/php/mago.toml`](../../examples/php/mago.toml), accepts the reference application and rejects one forbidden case per configured rule, each with the expected rule ID or analyzer code, plus one extension case that shows the example's configuration runs the extension. It also checks guard and analyzer baseline identity, expansion, substitution, and stale entries. Its fixtures name the reference modules; an adopting project writes equivalents for its own mapping.

With PHP 8.5, `pdo_sqlite`, and Composer 2 installed, from the repository root:

```sh
(cd enforcement/php/extension && composer install && vendor/bin/mago analyze && vendor/bin/phpunit)
(cd examples/php && composer install)
python3 enforcement/php/fixtures.py examples/php
```

`python3 scripts/verify.py` is the source of truth: it runs these checks, documentation, the [SQLite scenario](../../examples/php/fixture.md), an isolated copy/install smoke test of the extension, and the Go checks in pinned Docker images.
