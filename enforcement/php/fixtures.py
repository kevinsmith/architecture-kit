#!/usr/bin/env python3
"""Run the PHP profile's permitted and forbidden fixtures against a Mago configuration.

Usage: fixtures.py <application-root>

The application root must contain mago.toml, src/, and an installed vendor/ with
Mago. Fixtures run in an isolated copy, so the application is never modified.
"""

import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

PHP = "<?php\n\ndeclare(strict_types=1);\n\n"

# Each permitted fixture must pass both guard and analyzer alongside the application.
PERMITTED = {
    "src/run.php": PHP + "echo App\\Bootstrap\\Runtime::class, PHP_EOL;\n",
}

# (name, tool, rule or analyzer code expected in the diagnostic, file, source)
FORBIDDEN = [
    ("module internals", "guard", "R03", "src/Billing/Application/InternalsLeak.php",
     PHP + "namespace App\\Billing\\Application;\n\nuse App\\Access\\Domain\\Grant;\n\n"
     "final readonly class InternalsLeak\n{\n    public function __construct(private Grant $grant) {}\n}\n"),
    ("undeclared module dependency", "guard", "R03", "src/Access/Application/UndeclaredDependency.php",
     PHP + "namespace App\\Access\\Application;\n\nuse App\\Billing\\Contracts\\InvoiceVoided;\n\n"
     "final readonly class UndeclaredDependency\n{\n    public function __construct(private InvoiceVoided $event) {}\n}\n"),
    ("injected live clock in Domain", "guard", "R04", "src/Billing/Domain/LiveClock.php",
     PHP + "namespace App\\Billing\\Domain;\n\nuse Psr\\Clock\\ClockInterface;\n\n"
     "final readonly class LiveClock\n{\n    public function __construct(private ClockInterface $clock) {}\n}\n"),
    ("built-in effect in Domain", "guard", "R04", "src/Billing/Domain/Lottery.php",
     PHP + "namespace App\\Billing\\Domain;\n\n"
     "final readonly class Lottery\n{\n    public function draw(): int\n    {\n        return \\random_int(1, 6);\n    }\n}\n"),
    ("unqualified built-in effect in Domain", "guard", "R04", "src/Billing/Domain/Stamp.php",
     PHP + "namespace App\\Billing\\Domain;\n\n"
     "final readonly class Stamp\n{\n    public function now(): int\n    {\n        return time();\n    }\n}\n"),
    ("effectful published contract", "guard", "R05", "src/Billing/Contracts/EffectfulClock.php",
     PHP + "namespace App\\Billing\\Contracts;\n\n"
     "final readonly class EffectfulClock\n{\n    public function now(): int\n    {\n        return \\time();\n    }\n}\n"),
    ("mutable published class", "guard", "R05", "src/Billing/Contracts/MutableEvent.php",
     PHP + "namespace App\\Billing\\Contracts;\n\nfinal class MutableEvent\n{\n    public string $id = '';\n}\n"),
    ("property bag in published contract", "guard", "R05", "src/Billing/Contracts/BagEvent.php",
     PHP + "namespace App\\Billing\\Contracts;\n\n"
     "final readonly class BagEvent\n{\n    public function __construct(public \\stdClass $payload) {}\n}\n"),
    ("untyped array in published contract", "analyze", "imprecise-type", "src/Billing/Contracts/ArrayEvent.php",
     PHP + "namespace App\\Billing\\Contracts;\n\n"
     "final readonly class ArrayEvent\n{\n    public function __construct(public array $payload) {}\n}\n"),
    ("business code resolves composition", "guard", "R06", "src/Billing/Application/Locator.php",
     PHP + "namespace App\\Billing\\Application;\n\nuse App\\Bootstrap\\Runtime;\n\n"
     "final readonly class Locator\n{\n    public function __construct(private Runtime $runtime) {}\n}\n"),
    ("business code uses a container", "guard", "R06", "src/Billing/Application/ContainerLocator.php",
     PHP + "namespace App\\Billing\\Application;\n\nuse Psr\\Container\\ContainerInterface;\n\n"
     "final readonly class ContainerLocator\n{\n    public function __construct(private ContainerInterface $container) {}\n}\n"),
    ("entry point bypasses composition", "guard", "R06", "src/bypass.php",
     PHP + "echo new App\\Billing\\Application\\VoidCommand('inv', 'org-a')->invoiceId;\n"),
    ("unclassified namespace", "guard", "R02", "src/Billing/Reports/Summary.php",
     PHP + "namespace App\\Billing\\Reports;\n\nfinal readonly class Summary {}\n"),
    ("unclassified root-level function", "guard", "R02", "src/helpers.php",
     PHP + "function helper(): int\n{\n    return 1;\n}\n"),
    ("storage outside Infrastructure", "guard", "R02", "src/Billing/Application/Query.php",
     PHP + "namespace App\\Billing\\Application;\n\nuse PDO;\n\n"
     "final readonly class Query\n{\n    public function __construct(private PDO $pdo) {}\n}\n"),
    ("driving adapter uses a driven adapter", "guard", "R02", "src/Notifications/Presentation/Shortcut.php",
     PHP + "namespace App\\Notifications\\Presentation;\n\nuse App\\Billing\\Infrastructure\\PdoInvoiceStore;\n\n"
     "final readonly class Shortcut\n{\n    public function __construct(private PdoInvoiceStore $store) {}\n}\n"),
    ("production code uses test code", "guard", "R02", "src/Bootstrap/TestWiring.php",
     PHP + "namespace App\\Bootstrap;\n\nuse Tests\\Support\\Mailbox;\n\n"
     "final readonly class TestWiring\n{\n    public function __construct(private Mailbox $mailbox) {}\n}\n"),
    # The architecture extension's own tests cover its checks; this case shows the
    # application's mago.toml and architecture.json run it.
    ("another module's table", "analyze", "architecture/table-ownership", "src/Billing/Infrastructure/GrantQuery.php",
     PHP + "namespace App\\Billing\\Infrastructure;\n\nfinal readonly class GrantQuery\n{\n"
     "    public const string SQL = 'SELECT actor FROM access_grants WHERE actor = ?';\n}\n"),
]

# (tool, file, template, a second identical violation, a different violation)
BASELINES = [
    ("guard", "src/Billing/Domain/Legacy.php",
     PHP + "namespace App\\Billing\\Domain;\n\nfinal readonly class Legacy\n{\n"
     "    public function a(): int\n    {\n        return \\time();\n    }\n{extra}}\n",
     "\n    public function b(): int\n    {\n        return \\time();\n    }\n",
     "\n    public function b(): int\n    {\n        return \\mt_rand();\n    }\n"),
    ("analyze", "src/Billing/Infrastructure/LegacyReport.php",
     PHP + "namespace App\\Billing\\Infrastructure;\n\nfinal readonly class LegacyReport\n{\n"
     "    public const string A = 'SELECT actor FROM access_grants';\n{extra}}\n",
     "    public const string B = 'SELECT actor FROM access_grants WHERE actor = ?';\n",
     "    public const string B = 'SELECT actor FROM billing_invoices JOIN access_audit ON 1';\n"),
]


def mago(root, *arguments):
    result = subprocess.run(
        [str(root / "vendor/bin/mago"), *arguments, "--reporting-format=json"],
        cwd=root, capture_output=True, text=True,
    )
    try:
        issues = json.loads(result.stdout or "{}").get("issues", [])
    except json.JSONDecodeError:
        raise RuntimeError(f"mago {' '.join(arguments)} printed no report:\n{result.stdout}\n{result.stderr}")
    return result.returncode, issues


def files(issue):
    return {annotation["span"]["file_id"]["name"] for annotation in issue.get("annotations", [])}


def check(root, tool, label):
    code, issues = mago(root, tool)
    if code != 0 or issues:
        raise RuntimeError(f"{label}: mago {tool} rejected permitted code: {json.dumps(issues, indent=1)[:2000]}")


def expect_failure(root, tool, expected, path, label):
    code, issues = mago(root, tool)
    matching = [
        issue for issue in issues
        if path in files(issue) and (expected == issue.get("code") or any(expected in note for note in issue.get("notes", [])))
    ]
    if code == 0 or not matching:
        raise RuntimeError(f"{label}: expected {tool} to reject {path} with {expected}; got exit {code}, "
                           f"{json.dumps(issues, indent=1)[:2000]}")


def baseline(root, tool, path, template, same, other):
    source, baseline_file = root / path, root / f"{tool}-baseline.toml"
    common = [tool, "--baseline", str(baseline_file)]
    source.write_text(template.replace("{extra}", ""))
    subprocess.run([str(root / "vendor/bin/mago"), *common, "--generate-baseline"],
                   cwd=root, capture_output=True, check=True)
    strict = [*common, "--fail-on-out-of-sync-baseline"]
    if mago(root, *strict)[0] != 0:
        raise RuntimeError(f"{tool} baseline: an unchanged baselined violation failed")
    for label, extra in (("expanded", same), ("substituted", other)):
        source.write_text(template.replace("{extra}", extra))
        if mago(root, *strict)[0] == 0:
            raise RuntimeError(f"{tool} baseline: an {label} violation was accepted")
    source.unlink()
    if mago(root, *strict)[0] == 0:
        raise RuntimeError(f"{tool} baseline: a stale entry was accepted")
    baseline_file.unlink()


def main():
    if len(sys.argv) != 2:
        raise SystemExit(__doc__)
    application = Path(sys.argv[1]).resolve()
    if not (application / "vendor/bin/mago").exists():
        raise RuntimeError(f"{application}: run composer install first")
    with tempfile.TemporaryDirectory(prefix="php-architecture-fixtures-") as temporary:
        root = Path(temporary)
        for name in ("mago.toml", "architecture.json", "composer.json", "composer.lock"):
            shutil.copy(application / name, root / name)
        shutil.copytree(application / "src", root / "src")
        (root / "vendor").symlink_to(application / "vendor")
        for tool in ("guard", "analyze"):
            check(root, tool, "reference application")
        for path, source in PERMITTED.items():
            (root / path).write_text(source)
        for tool in ("guard", "analyze"):
            check(root, tool, "permitted fixtures")
        for label, tool, expected, path, source in FORBIDDEN:
            target = root / path
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(source)
            expect_failure(root, tool, expected, path, label)
            target.unlink()
        for case in BASELINES:
            baseline(root, *case)
    print(f"PHP fixtures passed: {len(PERMITTED)} permitted, {len(FORBIDDEN)} forbidden, and guard and "
          "analyzer baseline identity, expansion, substitution, and stale-entry checks.", flush=True)


if __name__ == "__main__":
    try:
        main()
    except (RuntimeError, subprocess.CalledProcessError, OSError) as error:
        print(f"PHP fixtures failed: {error}", file=sys.stderr)
        sys.exit(1)
