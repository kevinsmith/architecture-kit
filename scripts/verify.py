#!/usr/bin/env python3
"""Verify repository content, Go fixtures, and an isolated copy/install round trip."""

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
IMAGE = "golang:1.27.1@sha256:162be5298a40ed317005c8339c6de4d10d3eef336d66dc8e9259b03ab9d3a6d2"


def run(command, cwd=ROOT, **kwargs):
    print("+ " + " ".join(map(str, command)), flush=True)
    return subprocess.run(command, cwd=cwd, check=True, **kwargs)


def documentation(root, installed=False):
    root = root.resolve()
    boundary = root if installed else (ROOT / "kit").resolve()
    errors = []
    for source in root.rglob("*.md"):
        if ".git" in source.parts:
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
        ET.parse(svg)
    if errors:
        raise RuntimeError("\n".join(errors))


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
    known, covered, errors = set(required), set(), []
    for name in sorted(set(files) - {""}):
        if not name.startswith("examples/go/") or not name.endswith("_test.go"):
            continue
        source = root / name
        if not source.exists():
            continue
        # Ignore declarations quoted in comments or literals; this is not Go type analysis.
        text = re.sub(
            r"""//[^\n]*|/\*.*?\*/|"(?:\\.|[^"\\])*"|`[^`]*`|'(?:\\.|[^'\\])*'""",
            lambda match: re.sub(r"[^\n]", " ", match.group()),
            source.read_text(), flags=re.S,
        )
        for test in sorted(re.findall(r"^\s*func\s+(Test\w+)\s*\(", text, re.M)):
            prefix = re.match(r"Test((?:V\d+)+)", test)
            if prefix is None:
                continue
            labels = set(re.findall(r"V\d+", prefix.group(1)))
            covered.update(labels)
            unknown = sorted(labels - known)
            if unknown:
                errors.append(f"{source}: {test} references undefined scenario IDs {', '.join(unknown)}")
    missing = sorted(known - covered)
    if missing:
        errors.append(f"{specification}: missing Go test labels {', '.join(missing)}")
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
        if source.suffix in {".md", ".go", ".svg"}:
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


def golangci_version():
    version = re.search(r"^version: (v\S+)$", (ROOT / "enforcement/go/.custom-gcl.yml").read_text(), re.M)
    if version is None:
        raise RuntimeError("enforcement/go/.custom-gcl.yml must pin a golangci-lint version")
    return version.group(1)


def docker(mounts, workdir, script):
    volumes = ["-v", "architecture-kit-go-modules:/go/pkg/mod", "-v", "architecture-kit-go-build:/root/.cache/go-build"]
    for source, target in mounts.items():
        volumes += ["-v", f"{source}:{target}"]
    run(["docker", "run", "--rm", *volumes, "-w", workdir, IMAGE, "bash", "-ec", script])


def go_checks(use_docker):
    if use_docker:
        docker({ROOT: "/workspace"}, "/workspace", "BIN=/tmp\n" + GO_CHECKS)
        return
    with tempfile.TemporaryDirectory(prefix="architecture-checks-") as binaries:
        run(["bash", "-ec", GO_CHECKS], env={**os.environ, "BIN": binaries})


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


def installation(use_docker):
    with tempfile.TemporaryDirectory(prefix="architecture-adoption-") as temporary:
        base = Path(temporary)
        prepare_installation(base)
        version = golangci_version()
        if use_docker:
            docker({base: "/adoption"}, "/adoption", f"BASE=/adoption GOLANGCI_VERSION={version}\n" + SMOKE_TEST)
        else:
            run(["bash", "-ec", SMOKE_TEST], env={**os.environ, "BASE": str(base), "GOLANGCI_VERSION": version})
        print("Copy/install smoke test passed for the standalone command and the golangci-lint plugin, "
              "including intended R02 rejections.", flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--docker", action="store_true", help="use the pinned Go image even when local Go exists")
    parser.add_argument("--local", action="store_true", help="require the local Go toolchain (used by CI)")
    args = parser.parse_args()
    if args.docker and args.local:
        parser.error("choose --docker or --local")
    run([sys.executable, "-m", "unittest", "discover", "-s", "scripts", "-p", "test_*.py"])
    content()
    use_docker = args.docker or (not args.local and not shutil.which("go"))
    if use_docker and not shutil.which("docker"):
        raise RuntimeError("Install Go 1.27.1 with race-detector support, or Docker")
    # Host Python validates docs; the Go image only runs the Go phases.
    go_checks(use_docker)
    installation(use_docker)
    print("All verification passed.")


if __name__ == "__main__":
    try:
        main()
    except (RuntimeError, subprocess.CalledProcessError, OSError) as error:
        print(f"Verification failed: {error}", file=sys.stderr)
        sys.exit(1)
