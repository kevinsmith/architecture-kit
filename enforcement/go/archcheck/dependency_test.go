package archcheck

import (
	"strings"
	"testing"
)

func TestDeclaredModuleDependencies(t *testing.T) {
	publishedCall := map[string]string{
		"access/api/api.go":  "package api\nfunc Can() bool { return true }",
		"billing/usecase.go": "package billing\nimport \"fixture.test/app/access/api\"\nfunc Run() bool { return api.Can() }",
	}
	sharedContract := map[string]string{
		"contracts/event.go": "package contracts\ntype Event struct { ID string }",
		"billing/usecase.go": "package billing\nimport \"fixture.test/app/contracts\"\nfunc Run() contracts.Event { return contracts.Event{} }",
	}
	for _, tc := range []struct {
		name      string
		files     map[string]string
		dependsOn map[string][]string
		rule      string
	}{
		{"declared-call", publishedCall, map[string][]string{"billing": {"access"}}, ""},
		{"undeclared-call", publishedCall, nil, "R03"},
		{"reverse-declaration", publishedCall, map[string][]string{"access": {"billing"}}, "R03"},
		{"declared-contract", sharedContract, map[string][]string{"billing": {"shared"}}, ""},
		{"undeclared-contract", sharedContract, map[string][]string{"billing": {"access"}}, "R03"},
		{"composition-exempt", map[string]string{
			"assembly/wire.go":  "package assembly\nimport \"fixture.test/app/access/api\"\nfunc Wire() bool { return api.Can() }",
			"access/api/api.go": "package api\nfunc Can() bool { return true }",
		}, nil, ""},
	} {
		t.Run(tc.name, func(t *testing.T) {
			root := sourceFixture(t, tc.files)
			compiles(t, root)
			config := fixtureConfig()
			config.DependsOn = tc.dependsOn
			violations, err := analyze(t, root, config, Options{})
			if err != nil {
				t.Fatal(err)
			}
			if tc.rule == "" {
				if len(violations) != 0 {
					t.Fatalf("declared dependency rejected: %+v", violations)
				}
			} else if len(violations) != 1 || violations[0].Rule != tc.rule {
				t.Fatalf("expected exactly %s, got %+v", tc.rule, violations)
			}
		})
	}
}

func TestModuleDependencyConfigurationFails(t *testing.T) {
	root := sourceFixture(t, nil)
	for _, tc := range []struct {
		name      string
		dependsOn map[string][]string
		want      string
	}{
		{"cycle", map[string][]string{"billing": {"access"}, "access": {"shared"}, "shared": {"billing"}}, "module dependency cycle: access -> shared -> billing -> access"},
		{"self", map[string][]string{"billing": {"billing"}}, "invalid module dependency billing -> billing"},
		{"unknown-target", map[string][]string{"billing": {"acess"}}, "invalid module dependency billing -> acess"},
		{"unknown-source", map[string][]string{"biling": {"access"}}, "unknown module"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			config := fixtureConfig()
			config.DependsOn = tc.dependsOn
			if _, err := analyze(t, root, config, Options{}); err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("error = %v; want %q", err, tc.want)
			}
		})
	}
}
