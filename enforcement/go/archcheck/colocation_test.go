package archcheck

import "testing"

func TestColocatedRoles(t *testing.T) {
	for _, tc := range []struct {
		name, decision, usecase, target string
	}{
		{"pure decision beside orchestration", "package billing\nfunc Decide(n int) bool { return n > 0 }",
			"package billing\nfunc Load(n int) bool { return n > 0 }; func Run(n int) bool { return Decide(n) }", ""},
		{"domain calling orchestration", "package billing\nfunc Decide(n int) bool { return Load(n) }",
			"package billing\nfunc Load(n int) bool { return n > 0 }; func Run(n int) bool { return Decide(n) }", "Load"},
		{"domain calling a method declared in orchestration", "package billing\ntype Invoice struct{}; func Decide(i Invoice) int { return i.Total() }",
			"package billing\nfunc (Invoice) Total() int { return 1 }", "Total"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			root := sourceFixture(t, map[string]string{"billing/decision.go": tc.decision, "billing/usecase.go": tc.usecase})
			compiles(t, root)
			config := fixtureConfig()
			config.Files = map[string]string{"billing/decision.go": "domain"}
			violations, err := analyze(t, root, config, Options{})
			if err != nil {
				t.Fatal(err)
			}
			if tc.target != "" {
				if len(violations) != 1 || violations[0].Rule != "R02" || violations[0].Target != tc.target {
					t.Fatalf("wrong boundary diagnostic: %+v", violations)
				}
			} else if len(violations) != 0 {
				t.Fatalf("colocation rejected: %+v", violations)
			}
		})
	}
}

func TestFileRolePurityAndLocalShadowing(t *testing.T) {
	for _, tc := range []struct{ name, decision, rule string }{
		{"explicit-time", "package billing\nimport \"time\"\nfunc Decide(now time.Time) int { return now.Year() }", ""},
		{"live-clock", "package billing\nimport \"time\"\nfunc Decide() time.Time { return time.Now() }", "R04"},
		{"local-variable", "package billing\nfunc Decide(n int) int { Load := n; return Load }", ""},
	} {
		t.Run(tc.name, func(t *testing.T) {
			root := sourceFixture(t, map[string]string{
				"billing/decision.go": tc.decision,
				"billing/usecase.go":  "package billing\nfunc Load() int { return 1 }",
			})
			compiles(t, root)
			config := fixtureConfig()
			config.Files = map[string]string{"billing/decision.go": "domain"}
			violations, err := analyze(t, root, config, Options{})
			if err != nil {
				t.Fatal(err)
			}
			if tc.rule == "" {
				if len(violations) != 0 {
					t.Fatalf("valid code rejected: %+v", violations)
				}
			} else if len(violations) != 1 || violations[0].Rule != tc.rule {
				t.Fatalf("wrong purity diagnostic: %+v", violations)
			}
		})
	}
}
