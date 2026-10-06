package assembly

import (
	"context"
	"database/sql"
	"net/url"

	"example.com/architecture/invoice/access"
	accessdb "example.com/architecture/invoice/access/sqlite"
	"example.com/architecture/invoice/billing"
	billingdb "example.com/architecture/invoice/billing/sqlite"
	"example.com/architecture/invoice/notifications"
	"example.com/architecture/invoice/platform"
	_ "modernc.org/sqlite"
)

// Runtime exposes entry points, not adapters, so callers cannot bypass use cases.
type Runtime struct {
	db         *sql.DB
	Access     access.Service
	Billing    billing.Service
	Dispatcher billing.Dispatcher
}

func (r *Runtime) Close() error { return r.db.Close() }

// Assertions belong at the point where provider and consumer are assembled.
var _ billing.Access = access.Service{}
var _ billing.Subscriber = notifications.Service{}

func Open(path string, mailer notifications.Mailer, clock billing.Clock) (*Runtime, error) {
	location := url.URL{Scheme: "file", Path: path}
	query := url.Values{}
	query.Add("_pragma", "foreign_keys(1)")
	query.Add("_pragma", "busy_timeout(5000)")
	query.Add("_pragma", "journal_mode(WAL)")
	location.RawQuery = query.Encode()
	db, err := sql.Open("sqlite", location.String())
	if err != nil {
		return nil, err
	}
	db.SetMaxOpenConns(8)
	invoices := billingdb.New(db)
	grants := accessdb.New(db)
	for _, create := range []func(context.Context) error{invoices.CreateSchema, grants.CreateSchema} {
		if err := create(context.Background()); err != nil {
			db.Close()
			return nil, err
		}
	}
	if clock == nil {
		clock = platform.SystemClock{}
	}
	authorizer := access.Service{Grants: grants}
	return &Runtime{
		db: db, Access: authorizer,
		Billing:    billing.New(invoices, authorizer, clock),
		Dispatcher: billing.Dispatcher{Outbox: invoices, Subscriber: notifications.Service{Mailer: mailer}},
	}, nil
}
