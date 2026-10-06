package archcheck

import (
	"strings"
	"testing"
)

type roleCase struct {
	name, rule string
	files      map[string]string
	dependsOn  map[string][]string
}

func runRoleCases(t *testing.T, cases []roleCase) {
	t.Helper()
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			root := sourceFixture(t, tc.files)
			compiles(t, root)
			config := fixtureConfig()
			if tc.dependsOn != nil {
				config.DependsOn = tc.dependsOn
			}
			violations, err := analyze(t, root, config, Options{})
			if err != nil {
				t.Fatal(err)
			}
			if tc.rule == "" {
				if len(violations) != 0 {
					t.Fatalf("valid code rejected: %+v", violations)
				}
			} else if len(violations) != 1 || violations[0].Rule != tc.rule {
				t.Fatalf("expected exactly %s, got %+v", tc.rule, violations)
			}
		})
	}
}

var (
	accessPort = map[string]string{
		"billing/usecase.go":       "package billing\ntype Access interface { Can() bool }; func Run(a Access) bool { return a.Can() }",
		"access/private/access.go": "package private\ntype Service struct{}; func (Service) Can() bool { return true }",
		"assembly/wire.go":         "package assembly\nimport (\"fixture.test/app/billing\"; \"fixture.test/app/access/private\")\nfunc Wire() bool { return billing.Run(private.Service{}) }",
	}
	subscription = map[string]string{
		"billing/events/voided.go": "package events\ntype Voided struct { ID string }",
		"billing/dispatch.go":      "package billing\nimport (\"context\"; \"fixture.test/app/billing/events\")\ntype Subscriber interface { Deliver(context.Context, events.Voided) error }\ntype Dispatcher struct { Subscriber Subscriber }",
		"notify/service.go":        "package notify\nimport (\"context\"; \"fixture.test/app/billing/events\")\ntype Service struct{}; func (Service) Deliver(context.Context, events.Voided) error { return nil }",
		"assembly/wire.go":         "package assembly\nimport (\"fixture.test/app/billing\"; \"fixture.test/app/notify\")\nvar Dispatcher = billing.Dispatcher{Subscriber: notify.Service{}}",
	}
)

func TestCompositionWiring(t *testing.T) {
	runRoleCases(t, []roleCase{
		{"declared-injection", "", accessPort, map[string][]string{"billing": {"access"}}},
		{"undeclared-injection", "R03", accessPort, map[string][]string{}},
		{"subscription-port", "", subscription, map[string][]string{"billing": {"access"}, "notify": {"billing"}}},
		{"shared-infrastructure-adapter", "", map[string]string{
			"billing/usecase.go": "package billing\nimport \"time\"\ntype Clock interface { Now() time.Time }; func Run(c Clock) time.Time { return c.Now() }",
			"platform/clock.go":  "package platform\nimport \"time\"\ntype Clock struct{}; func (Clock) Now() time.Time { return time.Now() }",
			"assembly/wire.go":   "package assembly\nimport (\"time\"; \"fixture.test/app/billing\"; \"fixture.test/app/platform\")\nfunc Wire() time.Time { var clock billing.Clock = platform.Clock{}; return billing.Run(clock) }",
		}, nil},
	})
}

func TestSubscriptionNeedsTheSubscribersDependency(t *testing.T) {
	root := sourceFixture(t, subscription)
	compiles(t, root)
	config := fixtureConfig()
	config.DependsOn = map[string][]string{"billing": {"access", "notify"}}
	violations, err := analyze(t, root, config, Options{})
	if err != nil {
		t.Fatal(err)
	}
	for _, v := range violations {
		if v.File == "assembly/wire.go" && v.Rule == "R03" && strings.Contains(v.Message, "notify subscribes to billing's events through Subscriber") {
			return
		}
	}
	t.Fatalf("subscription wired in the wrong direction escaped: %+v", violations)
}

func TestAdapterSurfaces(t *testing.T) {
	invoice := "package domain\ntype Invoice struct { Status string }; func Decide(i Invoice) bool { return i.Status == \"open\" }"
	getter := "package billing\nimport \"fixture.test/app/billing/domain\"\nfunc Get() domain.Invoice { return domain.Invoice{} }; func Run() {}"
	entity := "package domain\ntype Status string\nfunc (s Status) String() string { return string(s) }\n" +
		"type NotFound struct{ ID string }\nfunc (e *NotFound) Error() string { return e.ID }\n" +
		"var ErrSettled = &NotFound{\"settled\"}\n" +
		"type Invoice struct { id string; status Status }\nfunc Restore(id string, s Status) Invoice { return Invoice{id, s} }\n" +
		"func (i Invoice) ID() string { return i.id }\nfunc (i Invoice) Status() Status { return i.status }"
	runRoleCases(t, []roleCase{
		{"infrastructure-implements-contract", "", map[string]string{
			"billing/domain/invoice.go": invoice,
			"billing/usecase.go":        "package billing\nimport \"fixture.test/app/billing/domain\"\ntype Store interface { Save(domain.Invoice) error }",
			"billing/db/store.go":       "package db\nimport (\"fixture.test/app/billing\"; \"fixture.test/app/billing/domain\")\ntype Store struct{}; func (Store) Save(i domain.Invoice) error { return nil }; var _ billing.Store = Store{}",
		}, nil},
		{"infrastructure-rehydrates-entity", "", map[string]string{
			"billing/domain/invoice.go": entity,
			"billing/db/store.go":       "package db\nimport \"fixture.test/app/billing/domain\"\nfunc Load(id string) domain.Invoice { return domain.Restore(id, \"open\") }\nfunc Save(i domain.Invoice) string { return i.ID() }",
		}, nil},
		{"infrastructure-calls-use-case", "R02", map[string]string{
			"billing/domain/invoice.go": invoice,
			"billing/usecase.go":        getter,
			"billing/db/store.go":       "package db\nimport \"fixture.test/app/billing\"\nfunc Save() { billing.Run() }",
		}, nil},
		{"presentation-maps-boundary-value", "", map[string]string{
			"billing/domain/invoice.go": invoice,
			"billing/usecase.go":        getter,
			"billing/web/handler.go":    "package web\nimport (\"fixture.test/app/billing\"; \"fixture.test/app/billing/domain\")\nfunc Show() string { var i domain.Invoice = billing.Get(); return i.Status }",
		}, nil},
		{"presentation-formats-values-and-maps-errors", "", map[string]string{
			"billing/domain/invoice.go": entity,
			"billing/usecase.go":        "package billing\nimport \"fixture.test/app/billing/domain\"\nfunc Get() (domain.Invoice, error) { return domain.Restore(\"x\", \"open\"), nil }",
			"billing/web/handler.go": "package web\nimport (\"errors\"; \"fixture.test/app/billing\"; \"fixture.test/app/billing/domain\")\n" +
				"func Show() (string, int) { i, err := billing.Get(); var missing *domain.NotFound; if errors.As(err, &missing) || errors.Is(err, domain.ErrSettled) { return \"\", 404 }; var status domain.Status = i.Status(); return status.String(), 200 }",
		}, nil},
		{"presentation-calls-domain-function", "R02", map[string]string{
			"billing/domain/invoice.go": invoice,
			"billing/usecase.go":        getter,
			"billing/web/handler.go":    "package web\nimport (\"fixture.test/app/billing\"; \"fixture.test/app/billing/domain\")\nfunc Show() bool { return domain.Decide(billing.Get()) }",
		}, nil},
		{"presentation-uses-internal-domain-type", "R02", map[string]string{
			"billing/domain/invoice.go": invoice + "\ntype Ledger struct{}",
			"billing/usecase.go":        getter,
			"billing/web/handler.go":    "package web\nimport \"fixture.test/app/billing/domain\"\nfunc Show() any { return domain.Ledger{} }",
		}, nil},
		{"presentation-embeds-template", "", map[string]string{
			"billing/web/page.html":  "<p>invoice</p>",
			"billing/web/handler.go": "package web\nimport _ \"embed\"\n//go:embed page.html\nvar page string\nfunc Page() string { return page }",
		}, nil},
		{"application-blank-import", "R06", map[string]string{
			"billing/usecase.go": "package billing\nimport _ \"time/tzdata\"",
		}, nil},
	})
}

func TestDomainPackageState(t *testing.T) {
	runRoleCases(t, []roleCase{
		{"lookup-table-read", "", map[string]string{
			"billing/domain/rates.go": "package domain\nvar rates = map[string]int{\"standard\": 1}; func Rate(kind string) int { return rates[kind] }",
		}, nil},
		{"package-counter-write", "R04", map[string]string{
			"billing/domain/rates.go": "package domain\nvar calls int; func Rate(kind string) int { calls++; return len(kind) }",
		}, nil},
		{"supplied-state-write", "", map[string]string{
			"billing/domain/invoice.go": "package domain\ntype Invoice struct { Status string }; func Void(i *Invoice) { i.Status = \"voided\" }",
		}, nil},
	})
}

func TestStorageOutsideInfrastructure(t *testing.T) {
	runRoleCases(t, []roleCase{
		{"infrastructure-storage", "", map[string]string{
			"billing/db/store.go": "package db\nimport \"database/sql\"\nfunc Open() (*sql.DB, error) { return sql.Open(\"driver\", \"dsn\") }",
		}, nil},
		{"application-storage", "R02", map[string]string{
			"billing/usecase.go": "package billing\nimport \"database/sql\"\nfunc Open() (*sql.DB, error) { return sql.Open(\"driver\", \"dsn\") }",
		}, nil},
	})
}
