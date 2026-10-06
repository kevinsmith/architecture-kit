package archcheck

import (
	"encoding/json"
	"go/token"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"
	"testing"

	"golang.org/x/tools/go/analysis"
	"golang.org/x/tools/go/analysis/checker"
	"golang.org/x/tools/go/packages"
)

func fixtureConfig() Config {
	return Config{
		Module: "fixture.test/app",
		Packages: []Package{
			{Path: "billing", Module: "billing", Role: "application"},
			{Path: "billing/domain", Module: "billing", Role: "domain"},
			{Path: "billing/db", Module: "billing", Role: "infrastructure"},
			{Path: "billing/web", Module: "billing", Role: "presentation"},
			{Path: "billing/events", Module: "billing", Role: "contract"},
			{Path: "notify", Module: "notify", Role: "application"},
			{Path: "access/private", Module: "access", Role: "application"},
			{Path: "access/api", Module: "access", Role: "application", Public: true},
			{Path: "contracts", Module: "shared", Role: "contract"},
			{Path: "ports", Module: "shared", Role: "contract", Effectful: true},
			{Path: "platform", Module: "shared", Role: "shared-infrastructure"},
			{Path: "assembly", Module: "composition", Role: "composition"},
			{Path: "testsupport", Module: "tests", Role: "test"},
		},
		PureImports: []string{"time"}, ForbiddenSymbols: map[string][]string{"time": {"Now"}}, ForbidInit: true,
		DependsOn:      map[string][]string{"billing": {"access"}},
		StorageImports: []string{"database/sql"},
	}
}

func sourceFixture(t *testing.T, files map[string]string) string {
	t.Helper()
	root := t.TempDir()
	write := func(name, content string) {
		t.Helper()
		path := filepath.Join(root, name)
		if err := os.MkdirAll(filepath.Dir(path), 0755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(path, []byte(content), 0644); err != nil {
			t.Fatal(err)
		}
	}
	write("go.mod", "module fixture.test/app\n\ngo 1.25.0\n")
	// Each shared test configuration entry names a real package. Individual cases
	// add the behavior being exercised rather than relying on stale entries.
	for _, pkg := range fixtureConfig().Packages {
		write(filepath.Join(pkg.Path, "fixture.go"), "package "+filepath.Base(pkg.Path)+"\n")
	}
	for name, content := range files {
		write(name, content)
	}
	return root
}

func compiles(t *testing.T, root string) {
	t.Helper()
	command := exec.Command("go", "test", "./...")
	command.Dir = root
	command.Env = append(os.Environ(), "GOWORK=off", "GOTOOLCHAIN=local")
	if output, err := command.CombinedOutput(); err != nil {
		t.Fatalf("fixture must compile independently of architecture checks: %v\n%s", err, output)
	}
}

// analyze runs the analyzer the way the standalone command does, over every package
// visible in the fixture's build configuration.
func analyze(t *testing.T, root string, config Config, opts Options, env ...string) ([]Violation, error) {
	t.Helper()
	data, err := json.Marshal(config)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(root, "architecture.json"), data, 0644); err != nil {
		t.Fatal(err)
	}
	pkgs, err := packages.Load(&packages.Config{
		Mode: packages.LoadAllSyntax,
		Dir:  root,
		Env:  append(os.Environ(), append([]string{"GOWORK=off", "GOTOOLCHAIN=local", "GOFLAGS=-mod=mod"}, env...)...),
	}, "./...")
	if err != nil {
		t.Fatal(err)
	}
	graph, err := checker.Analyze([]*analysis.Analyzer{New(opts)}, pkgs, nil)
	if err != nil {
		t.Fatal(err)
	}
	var found []Violation
	for _, action := range graph.Roots {
		if action.Err != nil {
			return nil, action.Err
		}
		found = append(found, action.Result.([]Violation)...)
	}
	sort.Slice(found, func(i, j int) bool { return found[i].key() < found[j].key() })
	return found, nil
}

func TestPermittedAndForbiddenFixturesCompile(t *testing.T) {
	cases := []struct {
		name, rule string
		files      map[string]string
	}{
		{"consumer-interface", "", map[string]string{
			"billing/usecase.go":       "package billing\ntype Access interface { Can() bool }; func Run(a Access) bool { return a.Can() }",
			"access/private/access.go": "package private\ntype Service struct{}; func (Service) Can() bool { return true }",
			"assembly/wire.go":         "package assembly\nimport (\"fixture.test/app/billing\"; \"fixture.test/app/access/private\")\nfunc Wire() bool { return billing.Run(private.Service{}) }",
		}},
		{"published-api", "", map[string]string{
			"access/private/access.go": "package private\nfunc Check() bool { return true }",
			"access/api/api.go":        "package api\nimport \"fixture.test/app/access/private\"\nfunc Can() bool { return private.Check() }",
			"billing/usecase.go":       "package billing\nimport \"fixture.test/app/access/api\"\nfunc Run() bool { return api.Can() }",
		}},
		{"private-dependency", "R03", map[string]string{
			"access/private/access.go": "package private\nfunc Can() bool { return true }",
			"billing/usecase.go":       "package billing\nimport \"fixture.test/app/access/private\"\nfunc Run() bool { return private.Can() }",
		}},
		{"domain-cannot-call-public-capability", "R03", map[string]string{
			"access/api/api.go":          "package api\nfunc Can() bool { return true }",
			"billing/domain/decision.go": "package domain\nimport \"fixture.test/app/access/api\"\nfunc Decide() bool { return api.Can() }",
		}},
		{"inward-layer", "", map[string]string{
			"billing/domain/decision.go": "package domain\nfunc Decide(n int) bool { return n > 0 }",
			"billing/usecase.go":         "package billing\nimport \"fixture.test/app/billing/domain\"\nfunc Run(n int) bool { return domain.Decide(n) }",
		}},
		{"concrete-adapter", "R02", map[string]string{
			"billing/db/store.go": "package db\nfunc Save() {}",
			"billing/usecase.go":  "package billing\nimport \"fixture.test/app/billing/db\"\nfunc Run() { db.Save() }",
		}},
		{"explicit-time", "", map[string]string{
			"billing/domain/decision.go": "package domain\nimport \"time\"\nfunc Decide(now, deadline time.Time) bool { return now.Before(deadline) }",
		}},
		{"clock-alias-reference", "R04", map[string]string{
			"billing/domain/decision.go": "package domain\nimport clock \"time\"\nfunc Decide() clock.Time { now := clock.Now; return now() }",
		}},
		{"clock-dot-import", "R04", map[string]string{
			"billing/domain/decision.go": "package domain\nimport . \"time\"\nfunc Decide() Time { return Now() }",
		}},
		{"injected-effectful-port", "R04", map[string]string{
			"ports/clock.go":             "package ports\nimport \"time\"\ntype Clock interface { Now() time.Time }",
			"billing/domain/decision.go": "package domain\nimport (\"time\"; \"fixture.test/app/ports\")\nfunc Decide(clock ports.Clock) time.Time { return clock.Now() }",
		}},
		{"effectful-port-context", "", map[string]string{
			"ports/mailer.go": "package ports\nimport \"context\"\ntype Mailer interface { Send(ctx context.Context, to string) error }",
		}},
		{"domain-context", "R04", map[string]string{
			"billing/domain/decision.go": "package domain\nimport \"context\"\nfunc Decide(ctx context.Context) bool { return ctx.Err() == nil }",
		}},
		{"unmarked-contract-context", "R05", map[string]string{
			"contracts/mailer.go": "package contracts\nimport \"context\"\ntype Mailer interface { Send(ctx context.Context, to string) error }",
		}},
		{"unreviewed-effect-package", "R04", map[string]string{
			"billing/domain/decision.go": "package domain\nimport \"os\"\nfunc Decide() string { return os.Getenv(\"MODE\") }",
		}},
		{"shared-clock", "", map[string]string{
			"platform/clock.go": "package platform\nimport \"time\"\ntype Clock struct{}; func (Clock) Now() time.Time { return time.Now() }",
		}},
		{"effect-in-contract", "R05", map[string]string{
			"contracts/clock.go": "package contracts\nimport \"time\"\nfunc Now() time.Time { return time.Now() }",
		}},
		{"implicit-wiring", "R06", map[string]string{
			"billing/usecase.go": "package billing\nvar service func(); func init() { service = func() {} }",
		}},
		{"global-service-lookup", "R06", map[string]string{
			"assembly/registry.go": "package assembly\nfunc Resolve() int { return 1 }",
			"billing/usecase.go":   "package billing\nimport \"fixture.test/app/assembly\"\nfunc Run() int { return assembly.Resolve() }",
		}},
		{"typed-collection-and-generics", "", map[string]string{
			"contracts/event.go": "package contracts\ntype Item struct { ID string }; type Event struct { Items []Item }; type List[T any] struct { Items []T }",
		}},
		{"property-bag", "R10", map[string]string{
			"contracts/event.go": "package contracts\ntype Event struct { Payload map[string]any }",
		}},
		{"empty-interface", "R10", map[string]string{
			"contracts/event.go": "package contracts\ntype Event struct { Payload interface{} }",
		}},
		{"unclassified-root", "R02", map[string]string{
			"main.go": "package main\nfunc main() {}",
		}},
		{"test-dependency", "R02", map[string]string{
			"testsupport/helper.go": "package testsupport\nfunc Fake() int { return 1 }",
			"billing/usecase.go":    "package billing\nimport \"fixture.test/app/testsupport\"\nfunc Run() int { return testsupport.Fake() }",
		}},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			root := sourceFixture(t, tc.files)
			compiles(t, root)
			violations, err := analyze(t, root, fixtureConfig(), Options{})
			if err != nil {
				t.Fatal(err)
			}
			if tc.rule == "" {
				if len(violations) != 0 {
					t.Fatalf("valid idiom rejected: %+v", violations)
				}
			} else if len(violations) != 1 || violations[0].Rule != tc.rule {
				t.Fatalf("expected exactly %s, got %+v", tc.rule, violations)
			}
		})
	}
}

func TestRootCanBeExplicitlyClassified(t *testing.T) {
	root := sourceFixture(t, map[string]string{"main.go": "package main\nfunc main() {}"})
	config := fixtureConfig()
	config.Packages = append(config.Packages, Package{Path: ".", Module: "composition", Role: "composition"})
	violations, err := analyze(t, root, config, Options{})
	if err != nil || len(violations) != 0 {
		t.Fatalf("classified root rejected: %+v, %v", violations, err)
	}
}

func TestEachBuildConfigurationIsCheckedSeparately(t *testing.T) {
	root := sourceFixture(t, map[string]string{
		"billing/domain/decision.go":      "package domain\nfunc Decide(n int) bool { return n > 0 }",
		"billing/domain/clock_windows.go": "//go:build windows\n\npackage domain\nimport \"time\"\nfunc now() time.Time { return time.Now() }",
	})
	if violations, err := analyze(t, root, fixtureConfig(), Options{}, "GOOS=linux"); err != nil || len(violations) != 0 {
		t.Fatalf("linux configuration should not see the windows file: %+v, %v", violations, err)
	}
	violations, err := analyze(t, root, fixtureConfig(), Options{}, "GOOS=windows")
	if err != nil || len(violations) != 1 || violations[0].Rule != "R04" {
		t.Fatalf("windows variant escaped inspection: %+v, %v", violations, err)
	}
}

func TestUnannotatedLocalPortRemainsAnExplicitCoverageGap(t *testing.T) {
	root := sourceFixture(t, map[string]string{
		"billing/domain/decision.go": "package domain\nimport \"time\"\ntype Clock interface { Now() time.Time }; func Decide(clock Clock) time.Time { return clock.Now() }",
	})
	compiles(t, root)
	violations, err := analyze(t, root, fixtureConfig(), Options{})
	if err != nil || len(violations) != 0 {
		t.Fatalf("coverage changed; update the documented indirect-effect limitation: %+v, %v", violations, err)
	}
}

func TestConfigurationCannotHideModuleOrRoleTypos(t *testing.T) {
	root := sourceFixture(t, map[string]string{"billing/usecase.go": "package billing\nfunc Run() {}"})
	config := fixtureConfig()
	config.Module = "wrong.test/module"
	if _, err := analyze(t, root, config, Options{}); err == nil {
		t.Fatal("mismatched Go module accepted")
	}
	config = fixtureConfig()
	config.Packages[0].Role = "aplication"
	if _, err := analyze(t, root, config, Options{}); err == nil || !strings.Contains(err.Error(), "unknown role") {
		t.Fatalf("role typo accepted: %v", err)
	}
}

func TestBaselineMatchesIdentityAndCount(t *testing.T) {
	a := Violation{Rule: "R03", File: "billing/usecase.go", Symbol: "imports", Target: "access/private"}
	b := Violation{Rule: "R03", File: "billing/usecase.go", Symbol: "imports", Target: "orders/private"}
	elsewhere := Violation{Rule: "R03", File: "orders/usecase.go", Symbol: "imports", Target: "access/private"}
	files := []string{"billing/usecase.go"}
	for _, tc := range []struct {
		name              string
		current, baseline []Violation
		issues            int
	}{
		{"empty", nil, nil, 0},
		{"existing", []Violation{a}, []Violation{a}, 0},
		{"new", []Violation{a}, nil, 1},
		{"substitution", []Violation{b}, []Violation{a}, 2},
		{"expansion", []Violation{a, a}, []Violation{a}, 1},
		{"stale", nil, []Violation{a}, 1},
		{"other-package-entry", nil, []Violation{elsewhere}, 0},
	} {
		t.Run(tc.name, func(t *testing.T) {
			if issues := reconcile(tc.current, tc.baseline, files, func(string) token.Pos { return token.NoPos }); len(issues) != tc.issues {
				t.Fatalf("issues = %+v, want %d", issues, tc.issues)
			}
		})
	}
}

func TestBaselineFile(t *testing.T) {
	root := sourceFixture(t, map[string]string{
		"access/private/access.go": "package private\nfunc Can() bool { return true }",
		"billing/usecase.go":       "package billing\nimport \"fixture.test/app/access/private\"\nfunc Run() bool { return private.Can() }",
	})
	debt := Violation{Rule: "R03", File: "billing/usecase.go", Symbol: "imports", Target: "fixture.test/app/access/private"}
	writeBaseline := func(entries ...Violation) {
		t.Helper()
		data, err := json.Marshal(entries)
		if err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(filepath.Join(root, "baseline.json"), data, 0644); err != nil {
			t.Fatal(err)
		}
	}
	writeBaseline(debt)
	if violations, err := analyze(t, root, fixtureConfig(), Options{Baseline: "baseline.json"}); err != nil || len(violations) != 0 {
		t.Fatalf("baselined violation reported: %+v, %v", violations, err)
	}
	resolved := debt
	resolved.Symbol = "Run"
	writeBaseline(debt, resolved)
	violations, err := analyze(t, root, fixtureConfig(), Options{Baseline: "baseline.json"})
	if err != nil || len(violations) != 1 || violations[0].Rule != "R16" {
		t.Fatalf("resolved baseline entry kept: %+v, %v", violations, err)
	}
	missing := debt
	missing.File = "billing/removed.go"
	writeBaseline(debt, missing)
	if _, err := analyze(t, root, fixtureConfig(), Options{Baseline: "baseline.json"}); err == nil || !strings.Contains(err.Error(), "missing file billing/removed.go") {
		t.Fatalf("entry for a deleted file accepted: %v", err)
	}
}
