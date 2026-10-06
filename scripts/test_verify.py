"""Regression coverage for documentation boundaries and evidence references."""

from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from verify import content, documentation, scenario_evidence


class DocumentationPaths(unittest.TestCase):
    def test_symlinked_installation_accepts_internal_links(self):
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary).resolve()
            actual = base / "actual"
            actual.mkdir()
            alias = base / "alias"
            alias.symlink_to(actual, target_is_directory=True)
            (actual / "README.md").write_text("[Guide](guide.md)\n")
            (actual / "guide.md").write_text("# Guide\n")
            documentation(alias, installed=True)

    def test_symlinked_installation_rejects_external_links(self):
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary).resolve()
            actual = base / "actual"
            actual.mkdir()
            alias = base / "alias"
            alias.symlink_to(actual, target_is_directory=True)
            (base / "outside.md").write_text("# Outside\n")
            (actual / "README.md").write_text("[Outside](../outside.md)\n")
            with self.assertRaisesRegex(RuntimeError, "nonportable link"):
                documentation(alias, installed=True)

    def test_internal_symlink_cannot_hide_an_external_target(self):
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary).resolve()
            actual = base / "actual"
            actual.mkdir()
            (base / "outside.md").write_text("# Outside\n")
            (actual / "guide.md").symlink_to(base / "outside.md")
            (actual / "README.md").write_text("[Guide](guide.md)\n")
            with self.assertRaisesRegex(RuntimeError, "nonportable link"):
                documentation(actual, installed=True)

    def test_valid_internal_kit_links_pass(self):
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary).resolve()
            docs = base / "kit/docs/architecture"
            docs.mkdir(parents=True)
            (docs / "README.md").write_text("[Guide](guide.md)\n")
            (docs / "guide.md").write_text("# Guide\n")
            with patch("verify.ROOT", base):
                documentation(base)

    def test_missing_relative_target_reports_source_and_target(self):
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary).resolve()
            source = base / "README.md"
            source.write_text("[Missing](missing.md#section)\n")
            with self.assertRaises(RuntimeError) as caught:
                documentation(base, installed=True)
            self.assertEqual(str(caught.exception), f"{source}: missing missing.md#section")

    def test_canonical_kit_links_cannot_escape_to_existing_repository_files(self):
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary).resolve()
            (base / "kit").mkdir()
            source = base / "kit/README.md"
            source.write_text("[Outside](../outside.md)\n")
            (base / "outside.md").write_text("# Outside\n")
            with patch("verify.ROOT", base), self.assertRaises(RuntimeError) as caught:
                documentation(base)
            self.assertEqual(str(caught.exception), f"{source}: nonportable link ../outside.md")

    def test_repository_cross_directory_links_remain_allowed(self):
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary).resolve()
            (base / "examples").mkdir()
            (base / "kit").mkdir()
            (base / "README.md").write_text("[Example](examples/README.md)\n")
            (base / "examples/README.md").write_text("[Guide](../kit/guide.md)\n")
            (base / "kit/guide.md").write_text("# Guide\n")
            with patch("verify.ROOT", base):
                documentation(base)

    def test_external_mailto_and_fragment_only_links_remain_allowed(self):
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary).resolve()
            (base / "README.md").write_text(
                "[HTTP](http://example.invalid/missing.md)\n"
                "[HTTPS](https://example.invalid/missing.md)\n"
                "[Contact](mailto:maintainer@example.invalid)\n"
                "[Section](#not-checked-for-heading-existence)\n"
            )
            documentation(base, installed=True)


class RuleReferences(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name).resolve()
        subprocess.run(["git", "init", "-q"], cwd=self.root, check=True)
        self.write("kit/docs/architecture/rules.md", "".join(
            f"## R{i:02d} — Rule\n" for i in range(1, 18)
        ))
        self.write("examples/void-invoice.md", "## Required evidence\n\n| V01 | Guarantee | R04 |\n")
        self.write("examples/go/scenario_test.go", "package fixture\nimport \"testing\"\n"
                   "func TestV01Fixture(t *testing.T) {}\n")

    def write(self, name, text):
        source = self.root / name
        source.parent.mkdir(parents=True, exist_ok=True)
        source.write_text(text)
        return source

    def check(self):
        # Discover files through real Git; whitespace diff commands are unrelated.
        with patch("verify.ROOT", self.root), patch("verify.run"):
            content()

    def test_missing_core_rule_heading_fails(self):
        core = self.root / "kit/docs/architecture/rules.md"
        core.write_text(core.read_text().replace("## R08 — Rule\n", ""))
        with self.assertRaisesRegex(RuntimeError, "Missing, reordered, or duplicate core rule IDs"):
            self.check()

    def test_reordered_core_rule_headings_fail(self):
        core = self.root / "kit/docs/architecture/rules.md"
        lines = core.read_text().splitlines(keepends=True)
        lines[0], lines[1] = lines[1], lines[0]
        core.write_text("".join(lines))
        with self.assertRaisesRegex(RuntimeError, "Missing, reordered, or duplicate core rule IDs"):
            self.check()

    def test_duplicate_core_rule_heading_fails(self):
        core = self.root / "kit/docs/architecture/rules.md"
        core.write_text(core.read_text() + "## R08 — Duplicate\n")
        with self.assertRaisesRegex(RuntimeError, "Missing, reordered, or duplicate core rule IDs"):
            self.check()

    def test_valid_ids_and_ranges_pass_in_all_source_types(self):
        self.write("README.md", "R01–R17, R02-R03, and R04.\n")
        self.write("enforcement/go/check.go", 'package check\nconst Rule = "R05"\n')
        self.write("enforcement/go/check_test.go", 'package check\nconst Expected = "R16"\n')
        self.write("docs/diagram.svg", '<svg><text>R02</text></svg>\n')
        self.check()

    def test_undefined_ids_fail_outside_the_kit(self):
        for name, text in {
            "README.md": "R99\n",
            "enforcement/README.md": "R99\n",
            "examples/README.md": "R99\n",
            "enforcement/go/check.go": 'package check\nconst Rule = "R99"\n',
            "enforcement/go/check_test.go": 'package check\nconst Expected = "R99"\n',
            "docs/diagram.svg": '<svg><text>R99</text></svg>\n',
        }.items():
            with self.subTest(source=name):
                source = self.write(name, text)
                with self.assertRaises(RuntimeError) as caught:
                    self.check()
                self.assertEqual(str(caught.exception), f"{source}: undefined rules R99")
                source.unlink()

    def test_malformed_ids_fail(self):
        for rule in ("R0", "R1", "R001", "R00", "R18", "R100"):
            with self.subTest(rule=rule):
                source = self.write("examples/README.md", rule + "\n")
                with self.assertRaises(RuntimeError) as caught:
                    self.check()
                self.assertEqual(str(caught.exception), f"{source}: undefined rules {rule}")

    def test_error_paths_and_ids_are_deterministic(self):
        self.write("z.md", "R99 R18\n")
        source = self.write("a.md", "R99 R18 R001 R99\n")
        with self.assertRaises(RuntimeError) as caught:
            self.check()
        self.assertEqual(str(caught.exception), f"{source}: undefined rules R001, R18, R99")

    def test_staged_sources_are_checked(self):
        name = "enforcement/go/check.go"
        source = self.write(name, 'package check\nconst Rule = "R99"\n')
        subprocess.run(["git", "add", name], cwd=self.root, check=True)
        with self.assertRaises(RuntimeError) as caught:
            self.check()
        self.assertEqual(str(caught.exception), f"{source}: undefined rules R99")

    def test_absent_staged_sources_remain_allowed(self):
        name = "examples/removed.md"
        source = self.write(name, "R99\n")
        subprocess.run(["git", "add", name], cwd=self.root, check=True)
        source.unlink()
        self.check()

    def test_python_regression_literals_are_outside_reference_scan(self):
        self.write("scripts/test_verify.py", 'INVALID_RULE = "R99"\n')
        self.check()

    def test_ignored_sources_are_outside_inventory(self):
        self.write(".gitignore", "/ignored.go\n")
        self.write("ignored.go", 'package ignored\nconst Rule = "R99"\n')
        self.check()


class ScenarioEvidence(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name).resolve()
        (self.root / "examples/go").mkdir(parents=True)
        self.specification = self.root / "examples/void-invoice.md"
        self.source = self.root / "examples/go/scenario_test.go"

    def check(self, required, tests, extra=""):
        self.specification.write_text(
            "# Scenario\n\n## Required evidence\n\n| ID | Guarantee | Rules |\n|---|---|---|\n"
            + "".join(f"| {label} | Guarantee | R04 |\n" for label in required) + extra
        )
        self.source.write_text('package fixture\nimport "testing"\n' + tests)
        scenario_evidence(self.root, ["examples/go/scenario_test.go"])

    def test_complete_correspondence_passes(self):
        self.check(["V01", "V02"], "func TestV01First(t *testing.T) {}\n"
                   "func TestV02Second(t *testing.T) {}\n")

    def test_combined_prefix_supplies_every_label(self):
        self.check(["V02", "V04", "V10"],
                   "func TestV02V04V10SuccessRepeatAndPushDelivery(t *testing.T) {}\n")

    def test_multiple_tests_can_cover_the_same_id(self):
        self.check(["V07"], "func TestV07First(t *testing.T) {}\n"
                   "func TestV07Second(t *testing.T) {}\n")

    def test_unlabeled_tests_remain_allowed(self):
        self.check(["V01"], "func TestV01First(t *testing.T) {}\n"
                   "func TestOtherCheck(t *testing.T) {}\n")

    def test_missing_id_fails(self):
        with self.assertRaisesRegex(RuntimeError, "missing Go test labels V02"):
            self.check(["V01", "V02"], "func TestV01First(t *testing.T) {}\n")

    def test_undefined_id_fails(self):
        with self.assertRaisesRegex(RuntimeError, "TestV99Unknown references undefined scenario IDs V99"):
            self.check(["V01"], "func TestV01First(t *testing.T) {}\n"
                       "func TestV99Unknown(t *testing.T) {}\n")

    def test_empty_inventory_fails(self):
        with self.assertRaisesRegex(RuntimeError, "empty scenario evidence inventory"):
            self.check([], "func TestOtherCheck(t *testing.T) {}\n")

    def test_duplicate_inventory_fails(self):
        with self.assertRaisesRegex(RuntimeError, "duplicate scenario IDs V01"):
            self.check(["V01", "V01"], "func TestV01First(t *testing.T) {}\n")

    def test_missing_and_undefined_ids_are_reported_deterministically(self):
        with self.assertRaises(RuntimeError) as caught:
            self.check(["V02", "V01"], "func TestV99V18Unknown(t *testing.T) {}\n")
        self.assertEqual(str(caught.exception),
                         f"{self.source}: TestV99V18Unknown references undefined scenario IDs V18, V99\n"
                         f"{self.specification}: missing Go test labels V01, V02")

    def test_inventory_is_not_hardcoded(self):
        self.check(["V12"], "func TestV12NewEvidence(t *testing.T) {}\n")

    def test_other_sections_do_not_add_requirements(self):
        self.check(["V01"], "func TestV01First(t *testing.T) {}\n",
                   extra="\n## Other section\n\n| V99 | Not required evidence | R04 |\n")

    def test_mentions_in_comments_and_literals_do_not_supply_labels(self):
        with self.assertRaisesRegex(RuntimeError, "missing Go test labels V02"):
            self.check(["V01", "V02"], '''
func TestV01Real(t *testing.T) {}
// func TestV02LineComment(t *testing.T) {}
/*
func TestV02BlockComment(t *testing.T) {}
*/
var quoted = "func TestV02Quoted(t *testing.T) {}"
var example = `
func TestV02RawString(t *testing.T) {}
`
''')


if __name__ == "__main__":
    unittest.main()
