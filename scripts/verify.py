#!/usr/bin/env python3
"""Verify repository content, then run the Go and PHP phases in pinned Docker images."""

import argparse
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
GO_IMAGE = "golang:1.27.1@sha256:162be5298a40ed317005c8339c6de4d10d3eef336d66dc8e9259b03ab9d3a6d2"
# The official Composer image bundles PHP 8.5 with pdo_sqlite, zip, and Python 3.
PHP_IMAGE = "composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0"


def run(command, cwd=ROOT, **kwargs):
    print("+ " + " ".join(map(str, command)), flush=True)
    return subprocess.run(command, cwd=cwd, check=True, **kwargs)


def documentation(root, installed=False):
    root = root.resolve()
    boundary = root if installed else (ROOT / "kit").resolve()
    errors = []
    skipped = {".git", "vendor"}  # Repository metadata and installed dependencies.
    for source in root.rglob("*.md"):
        if skipped & set(source.parts):
            continue
        for target in re.findall(r"\]\(([^)]+)\)", source.read_text()):
            if "://" in target or target.startswith(("mailto:", "#")):
                continue
            path = (source.parent / target.split("#", 1)[0]).resolve()
            if not path.exists():
                errors.append(f"{source}: missing {target}")
            if source.is_relative_to(boundary) and not path.is_relative_to(boundary):
                errors.append(f"{source}: nonportable link {target}")
    for svg in root.rglob("*.svg"):
        if not skipped & set(svg.parts):
            ET.parse(svg)
    if errors:
        raise RuntimeError("\n".join(errors))


# Each executable example must label tests with every required scenario ID. An
# example directory that exists is required; its tests are matched by name only.
SCENARIO_TESTS = {
    "Go": ("examples/go/", "_test.go", r"^\s*func\s+(Test\w+)\s*\(", r"Test((?:V\d+)+)",
           r"""//[^\n]*|/\*.*?\*/|"(?:\\.|[^"\\])*"|`[^`]*`|'(?:\\.|[^'\\])*'"""),
    "PHP": ("examples/php/tests/", "Test.php", r"^\s*public\s+function\s+(test\w+)\s*\(", r"test((?:V\d+)+)",
            r"""//[^\n]*|#(?!\[)[^\n]*|/\*.*?\*/|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'"""),
}


def scenario_evidence(root, files):
    specification = root / "examples/void-invoice.md"
    section = re.search(
        r"^## Required evidence[ \t]*\n(.*?)(?=^## |\Z)",
        specification.read_text(), re.M | re.S,
    )
    required = re.findall(r"^\|[ \t]*(V\d+)[ \t]*\|", section.group(1) if section else "", re.M)
    if not required:
        raise RuntimeError(f"{specification}: empty scenario evidence inventory")
    duplicates = sorted({label for label in required if required.count(label) > 1})
    if duplicates:
        raise RuntimeError(f"{specification}: duplicate scenario IDs {', '.join(duplicates)}")
    known, errors = set(required), []
    for language, (directory, suffix, declaration, label, ignored) in SCENARIO_TESTS.items():
        if not (root / directory).is_dir():
            continue
        covered = set()
        for name in sorted(set(files) - {""}):
            if not name.startswith(directory) or not name.endswith(suffix):
                continue
            source = root / name
            if not source.exists():
                continue
            # Ignore declarations quoted in comments or literals; this is not type analysis.
            text = re.sub(ignored, lambda match: re.sub(r"[^\n]", " ", match.group()), source.read_text(), flags=re.S)
            for test in sorted(re.findall(declaration, text, re.M)):
                prefix = re.match(label, test)
                if prefix is None:
                    continue
                labels = set(re.findall(r"V\d+", prefix.group(1)))
                covered.update(labels)
                unknown = sorted(labels - known)
                if unknown:
                    errors.append(f"{source}: {test} references undefined scenario IDs {', '.join(unknown)}")
        missing = sorted(known - covered)
        if missing:
            errors.append(f"{specification}: missing {language} test labels {', '.join(missing)}")
    if errors:
        raise RuntimeError("\n".join(errors))


def content():
    documentation(ROOT)
    core = (ROOT / "kit/docs/architecture/rules.md").read_text()
    ids = re.findall(r"^## (R\d+) ", core, re.M)
    if ids != [f"R{i:02d}" for i in range(1, 18)]:
        raise RuntimeError("Missing, reordered, or duplicate core rule IDs")
    files = subprocess.check_output(
        ["git", "ls-files", "--cached", "--others", "--exclude-standard", "-z"], cwd=ROOT
    ).decode().split("\0")
    for name in sorted(set(files) - {""}):
        source = ROOT / name
        if not source.exists():  # Staged removals can precede first-commit preparation.
            continue
        data = source.read_bytes()
        if b"\0" in data:
            raise RuntimeError(f"Unexpected binary artifact: {name}")
        text = data.decode()
        if source.suffix in {".md", ".go", ".svg", ".php", ".toml"}:
            unknown = set(re.findall(r"\bR\d+\b", text)) - set(ids)
            if unknown:
                raise RuntimeError(f"{source}: undefined rules {', '.join(sorted(unknown))}")
        if data and not data.endswith(b"\n"):
            raise RuntimeError(f"Missing final newline: {name}")
        if any(line.rstrip() != line for line in text.splitlines()):
            raise RuntimeError(f"Trailing whitespace: {name}")
    scenario_evidence(ROOT, files)
    run(["git", "diff", "--check"])
    run(["git", "diff", "--cached", "--check"])


GO_CHECKS = """
test -z "$(gofmt -l enforcement/go examples/go)" || { gofmt -l enforcement/go examples/go; exit 1; }
cd enforcement/go
go test -race -count=1 -timeout=600s ./...
go build -o "$BIN/archcheck" ./cmd/archcheck
cd ../../examples/go
"$BIN/archcheck" ./...
go test -race -count=1 -timeout=120s ./...
"""

# Both supported hosts must accept the reference and reject unclassified source.
SMOKE_TEST = """
reject() {
  cp "$BASE/unclassified.go" "$BASE/application/"
  if "$@" > "$BASE/result" 2>&1; then echo "accepted unclassified source: $*"; exit 1; fi
  grep -q "unclassified.go.*R02" "$BASE/result" || { cat "$BASE/result"; exit 1; }
  rm "$BASE/application/unclassified.go"
}
cd "$BASE/architecture-tool"
go build -o "$BASE/archcheck" ./cmd/archcheck
cd "$BASE/application"
"$BASE/archcheck" ./...
reject "$BASE/archcheck" ./...
GOBIN="$BASE/bin" go install "github.com/golangci/golangci-lint/v2/cmd/golangci-lint@$GOLANGCI_VERSION"
cd "$BASE/architecture-tool"
"$BASE/bin/golangci-lint" custom
cd "$BASE/application"
cp "$BASE/architecture-tool/golangci.example.yml" .golangci.yml
"$BASE/architecture-tool/golangci-lint-archcheck" run --enable-only=archcheck ./...
reject "$BASE/architecture-tool/golangci-lint-archcheck" run --enable-only=archcheck ./...
"""


# The checkout is mounted read-only. The container works on a copy that keeps the
# example's relative path to the extension. Each vendor/ is a named volume, so a host
# vendor/ with other platforms' binaries is never used.
PHP_CHECKS = """
copy() { mkdir -p "/work/$1" && tar -C "/workspace/$1" --exclude=./vendor --exclude=./.phpunit.cache -cf - . | tar -C "/work/$1" -xf -; }
copy enforcement/php
copy examples/php
cd /work/enforcement/php/extension
composer validate --no-check-publish
composer install --no-interaction --no-progress
vendor/bin/mago analyze
vendor/bin/phpunit
cd /work/examples/php
composer validate --strict --no-check-publish
composer install --no-interaction --no-progress
vendor/bin/mago guard
vendor/bin/mago analyze
vendor/bin/phpunit
python3 /work/enforcement/php/fixtures.py .
"""

# The installed extension must accept the reference and reject another module's table.
PHP_SMOKE_TEST = """
cd /adoption/application
composer update example/architecture-check --no-interaction --no-progress
vendor/bin/mago guard
vendor/bin/mago analyze
cp /adoption/GrantQuery.php src/Billing/Infrastructure/
if vendor/bin/mago analyze > /adoption/result 2>&1; then echo "accepted another module's table"; exit 1; fi
grep -q "architecture/table-ownership" /adoption/result && grep -q "R09" /adoption/result || { cat /adoption/result; exit 1; }
"""

PHP_ENV = ["COMPOSER_CACHE_DIR=/tmp/composer", "GITHUB_TOKEN"]


def php_checks():
    volumes = {"architecture-kit-php-vendor": "/work/examples/php/vendor",
               "architecture-kit-php-extension-vendor": "/work/enforcement/php/extension/vendor",
               "architecture-kit-composer-cache": "/tmp/composer"}
    docker(PHP_IMAGE, volumes, {f"{ROOT}": "/workspace:ro"}, "/workspace", PHP_CHECKS, shell="sh", env=PHP_ENV)


def prepare_php_installation(base):
    # Install as adopters do: the extension in the application's tooling directory, a
    # path repository, and a mapping that starts from the reviewed template.
    app = base / "application"
    shutil.copytree(ROOT / "examples/php", app,
                    ignore=shutil.ignore_patterns("vendor", ".phpunit.cache", "fixture.md", ".DS_Store"))
    shutil.copytree(ROOT / "enforcement/php/extension", app / "tools/architecture-check",
                    ignore=shutil.ignore_patterns("vendor", ".phpunit.cache", ".DS_Store"))
    composer = json.loads((app / "composer.json").read_text())
    composer["repositories"] = [{"type": "path", "url": "tools/architecture-check"}]
    (app / "composer.json").write_text(json.dumps(composer, indent=4) + "\n")
    config = json.loads((ROOT / "enforcement/php/extension/architecture.template.json").read_text())
    config.update(json.loads((ROOT / "examples/php/architecture.json").read_text()))
    (app / "architecture.json").write_text(json.dumps(config, indent=2) + "\n")
    (app / "vendor").mkdir()
    (base / "GrantQuery.php").write_text(
        "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Billing\\Infrastructure;\n\n"
        "final readonly class GrantQuery\n{\n    public const string SQL = 'SELECT actor FROM access_grants';\n}\n")


def php_installation():
    with tempfile.TemporaryDirectory(prefix="architecture-php-adoption-") as temporary:
        base = Path(temporary)
        prepare_php_installation(base)
        # Return the container's root-owned files, except the vendor volume, so the host can remove them.
        owner = (f"trap 'find /adoption -path /adoption/application/vendor -prune -o "
                 f"-exec chown {os.getuid()}:{os.getgid()} {{}} +' EXIT\n")
        volumes = {"architecture-kit-php-adoption-vendor": "/adoption/application/vendor",
                   "architecture-kit-composer-cache": "/tmp/composer"}
        docker(PHP_IMAGE, volumes, {base: "/adoption"}, "/adoption", owner + PHP_SMOKE_TEST, shell="sh", env=PHP_ENV)
        print("PHP copy/install smoke test passed for the architecture extension, including an intended R09 rejection.",
              flush=True)


def golangci_version():
    version = re.search(r"^version: (v\S+)$", (ROOT / "enforcement/go/.custom-gcl.yml").read_text(), re.M)
    if version is None:
        raise RuntimeError("enforcement/go/.custom-gcl.yml must pin a golangci-lint version")
    return version.group(1)


GO_VOLUMES = {"architecture-kit-go-modules": "/go/pkg/mod", "architecture-kit-go-build": "/root/.cache/go-build"}


def docker(image, volumes, mounts, workdir, script, shell="bash", env=()):
    # Named volumes cache dependencies between runs. A bare env name passes the
    # host value only when it is set, such as GITHUB_TOKEN for Mago's download.
    arguments = []
    for source, target in {**volumes, **mounts}.items():
        arguments += ["-v", f"{source}:{target}"]
    for variable in env:
        arguments += ["-e", variable]
    run(["docker", "run", "--rm", *arguments, "-w", workdir, image, shell, "-ec", script])


def go_checks():
    # The container's root user does not own the mounted checkout, so Git refuses to
    # report VCS status; verification binaries need no VCS stamp.
    docker(GO_IMAGE, GO_VOLUMES, {ROOT: "/workspace"}, "/workspace", "BIN=/tmp\n" + GO_CHECKS,
           env=["GOFLAGS=-buildvcs=false"])


def prepare_installation(base):
    # Separate roots deliberately test the documented tooling placement.
    app, tool = base / "application", base / "architecture-tool"
    shutil.copytree(ROOT / "examples/go", app, ignore=shutil.ignore_patterns("fixture.md", ".DS_Store"))
    # Configure as adopters do: reviewed template defaults plus the project's own mapping.
    config = json.loads((ROOT / "enforcement/go/architecture.template.json").read_text())
    mapping = json.loads((ROOT / "examples/go/architecture.json").read_text())
    for key in ("module", "packages", "files", "depends_on", "storage_imports", "datasets",
                "transaction_functions", "external_effects"):
        config[key] = mapping[key]
    (app / "architecture.json").write_text(json.dumps(config, indent=2) + "\n")
    docs = app / "docs/architecture"
    shutil.copytree(ROOT / "kit/docs/architecture", docs)
    shutil.copy(ROOT / "LICENSE", docs / "LICENSE")
    shutil.copy(ROOT / "kit/AGENTS.md", app / "AGENTS.md")
    shutil.copytree(ROOT / "enforcement/go", tool, ignore=shutil.ignore_patterns("*.md", ".DS_Store"))
    for source in (ROOT / "LICENSE", docs / "COPYRIGHT"):
        shutil.copy(source, tool / source.name)
    (base / "unclassified.go").write_text("package invoice\nfunc Unclassified() {}\n")
    documentation(app, installed=True)
    return app, tool


def installation():
    with tempfile.TemporaryDirectory(prefix="architecture-adoption-") as temporary:
        base = Path(temporary)
        prepare_installation(base)
        version = golangci_version()
        # Return the container's root-owned files so the host can remove the directory.
        owner = f"trap 'chown -R {os.getuid()}:{os.getgid()} /adoption' EXIT\n"
        docker(GO_IMAGE, GO_VOLUMES, {base: "/adoption"}, "/adoption",
               owner + f"BASE=/adoption GOLANGCI_VERSION={version}\n" + SMOKE_TEST)
        print("Copy/install smoke test passed for the standalone command and the golangci-lint plugin, "
              "including intended R02 rejections.", flush=True)


def main():
    argparse.ArgumentParser(description=__doc__).parse_args()
    if not shutil.which("docker"):
        raise RuntimeError("Install Docker; the Go and PHP phases run only in the pinned images")
    run([sys.executable, "-m", "unittest", "discover", "-s", "scripts", "-p", "test_*.py"])
    content()
    # Host Python validates docs; the images run the language phases, here and in CI.
    go_checks()
    installation()
    php_checks()
    php_installation()
    print("All verification passed.")


if __name__ == "__main__":
    try:
        main()
    except (RuntimeError, subprocess.CalledProcessError, OSError) as error:
        print(f"Verification failed: {error}", file=sys.stderr)
        sys.exit(1)
