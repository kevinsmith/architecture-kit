package archcheck

import (
	"slices"
	"strings"
	"testing"
)

func effectsConfig() Config {
	config := fixtureConfig()
	config.Datasets = map[string][]string{"billing": {"billing_invoices", "billing_outbox"}, "access": {"access_grants"}}
	config.TransactionFunctions = []string{"fixture.test/app/billing.Store.Run"}
	config.ExternalEffects = []string{"fixture.test/app/billing.Mailer"}
	return config
}

func runEffectCases(t *testing.T, cases []roleCase) {
	t.Helper()
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			root := sourceFixture(t, tc.files)
			compiles(t, root)
			violations, err := analyze(t, root, effectsConfig(), Options{})
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

func adapterSQL(sql string) map[string]string {
	return map[string]string{"billing/db/store.go": "package db\nimport (\"context\"; \"database/sql\")\nfunc Run(ctx context.Context, db *sql.DB) error { _, err := db.ExecContext(ctx, `" + sql + "`); return err }"}
}

func TestTableOwnership(t *testing.T) {
	runEffectCases(t, []roleCase{
		{"owned-table", "", adapterSQL("UPDATE billing_invoices SET status = 'voided' WHERE id = ?"), nil},
		{"other-modules-table", "R09", adapterSQL("SELECT actor FROM access_grants WHERE actor = ?"), nil},
		{"undeclared-table", "R09", adapterSQL("DELETE FROM audit_log"), nil},
		{"join-across-modules", "R09", adapterSQL("SELECT i.id FROM billing_invoices i JOIN access_grants g ON g.organization = i.organization_id"), nil},
		{"comma-separated-tables", "R09", adapterSQL("SELECT 1 FROM billing_invoices AS i, access_grants g WHERE g.actor = ?"), nil},
		{"quoted-identifier", "R09", adapterSQL(`SELECT "actor" FROM "access_grants"`), nil},
		{"common-table-expression", "", adapterSQL("WITH open_invoices AS (SELECT id FROM billing_invoices) SELECT id FROM open_invoices"), nil},
		{"schema-and-references", "", adapterSQL("CREATE TABLE IF NOT EXISTS billing_outbox (id TEXT, invoice_id TEXT REFERENCES billing_invoices(id))"), nil},
		{"comment", "", adapterSQL("SELECT id FROM billing_invoices -- access_grants is read elsewhere"), nil},
		{"dynamic-sql", "", map[string]string{"billing/db/store.go": "package db\nimport (\"context\"; \"database/sql\")\nfunc Run(ctx context.Context, db *sql.DB, query string) error { _, err := db.ExecContext(ctx, query); return err }"}, nil},
	})
}

func TestSQLTables(t *testing.T) {
	for sql, want := range map[string][]string{
		"INSERT OR IGNORE INTO access_grants VALUES (?, ?, ?)": {"access_grants"},
		"TRUNCATE TABLE billing_outbox":                        {"billing_outbox"},
		"DROP TABLE IF EXISTS billing.archive":                 {"billing.archive"},
		"SELECT 1":                                             nil,
		"INSERT INTO billing_outbox (id) VALUES (?) ON CONFLICT (id) DO UPDATE SET delivered = 0":       {"billing_outbox"},
		"INSERT INTO billing_outbox (id) VALUES (?) ON DUPLICATE KEY UPDATE delivered = 0":              {"billing_outbox"},
		"SELECT id FROM billing_outbox WHERE delivered = 0 ORDER BY id LIMIT 10 FOR UPDATE SKIP LOCKED": {"billing_outbox"},
		"SELECT value FROM unnest($1::text[]) AS value":                                                 nil,
		"SELECT EXTRACT(YEAR FROM deadline), TRIM(BOTH ' ' FROM contact) FROM billing_invoices":         {"billing_invoices"},
		"SELECT id FROM billing_invoices WHERE status IS NOT DISTINCT FROM previous_status":             {"billing_invoices"},
		"SELECT id FROM billing_invoices WHERE id IN (SELECT invoice_id FROM billing_outbox)":           {"billing_invoices", "billing_outbox"},
		"sqlite": nil,
	} {
		if got := sqlTables(sql); !slices.Equal(got, want) {
			t.Errorf("sqlTables(%q) = %v, want %v", sql, got, want)
		}
	}
}

func TestTransactionEffects(t *testing.T) {
	ports := "package billing\nimport \"context\"\ntype Tx interface { Save() error }\ntype Store interface { Run(context.Context, func(Tx) error) error }\ntype Mailer interface { Send(context.Context, string) error }\n"
	runEffectCases(t, []roleCase{
		{"effect-after-commit", "", map[string]string{"billing/usecase.go": ports +
			"func Void(ctx context.Context, s Store, m Mailer) error { if err := s.Run(ctx, func(tx Tx) error { return tx.Save() }); err != nil { return err }; return m.Send(ctx, \"voided\") }"}, nil},
		{"effect-inside-transaction", "R13", map[string]string{"billing/usecase.go": ports +
			"func Void(ctx context.Context, s Store, m Mailer) error { return s.Run(ctx, func(tx Tx) error { if err := tx.Save(); err != nil { return err }; return m.Send(ctx, \"voided\") }) }"}, nil},
		{"goroutine-inside-transaction", "R13", map[string]string{"billing/usecase.go": ports +
			"func Void(ctx context.Context, s Store) error { return s.Run(ctx, func(tx Tx) error { go func() {}(); return tx.Save() }) }"}, nil},
	})
}

func TestEffectConfigurationFails(t *testing.T) {
	root := sourceFixture(t, nil)
	for _, tc := range []struct {
		name   string
		change func(*Config)
		want   string
	}{
		{"duplicate-owner", func(c *Config) { c.Datasets["access"] = append(c.Datasets["access"], "billing_invoices") }, "needs exactly one owner"},
		{"unknown-module", func(c *Config) { c.Datasets["ledger"] = []string{"entries"} }, "datasets names unknown module"},
		{"malformed-name", func(c *Config) { c.ExternalEffects = []string{"Mailer"} }, "invalid qualified name"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			config := effectsConfig()
			tc.change(&config)
			if _, err := analyze(t, root, config, Options{}); err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("error = %v; want %q", err, tc.want)
			}
		})
	}
}
