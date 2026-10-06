package archcheck

import (
	"strings"
	"testing"
)

func TestDiagnosticsSuggestFixes(t *testing.T) {
	for _, tc := range []struct {
		name  string
		files map[string]string
		want  string
	}{
		{"adapter-import", map[string]string{
			"billing/db/store.go": "package db\nfunc Save() {}",
			"billing/usecase.go":  "package billing\nimport \"fixture.test/app/billing/db\"\nfunc Run() { db.Save() }",
		}, "billing application cannot depend on billing infrastructure; depend on an interface the consumer defines and wire the adapter in composition"},
		{"module-internals", map[string]string{
			"access/private/access.go": "package private\nfunc Can() bool { return true }",
			"billing/usecase.go":       "package billing\nimport \"fixture.test/app/access/private\"\nfunc Run() bool { return private.Can() }",
		}, "billing application cannot depend on access application; use the module's published contract or public API"},
		{"composition-lookup", map[string]string{
			"assembly/registry.go": "package assembly\nfunc Resolve() int { return 1 }",
			"billing/usecase.go":   "package billing\nimport \"fixture.test/app/assembly\"\nfunc Run() int { return assembly.Resolve() }",
		}, "billing application cannot depend on composition; take the dependency as a parameter that composition supplies"},
		{"domain-calls-capability", map[string]string{
			"access/api/api.go":          "package api\nfunc Can() bool { return true }",
			"billing/domain/decision.go": "package domain\nimport \"fixture.test/app/access/api\"\nfunc Decide() bool { return api.Can() }",
		}, "Domain receives values; move the call to Application and pass the result in"},
		{"live-clock", map[string]string{
			"billing/domain/decision.go": "package domain\nimport \"time\"\nfunc Decide() time.Time { return time.Now() }",
		}, "move the effect to Application and pass in any value it produces"},
		{"unclassified-root", map[string]string{
			"main.go": "package main\nfunc main() {}",
		}, `add a packages entry {"path": ".", "module": "<module>", "role": "<role>"}`},
	} {
		t.Run(tc.name, func(t *testing.T) {
			root := sourceFixture(t, tc.files)
			violations, err := analyze(t, root, fixtureConfig(), Options{})
			if err != nil {
				t.Fatal(err)
			}
			if len(violations) != 1 || !strings.Contains(violations[0].Message, tc.want) {
				t.Fatalf("diagnostic = %+v; want message containing %q", violations, tc.want)
			}
		})
	}
}
