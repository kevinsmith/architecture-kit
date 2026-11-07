package billing

import (
	"context"
	"database/sql"

	"example.com/architecture/invoice/billing/internal/sqlite"
)

// Open assembles Billing over a configured database. It is composition, kept in
// Billing's tree because only this tree can import Billing's internal adapters.
func Open(ctx context.Context, db *sql.DB, access Access, clock Clock, subscriber Subscriber) (Service, Dispatcher, error) {
	store := sqlite.New(db)
	if err := store.CreateSchema(ctx); err != nil {
		return Service{}, Dispatcher{}, err
	}
	return newService(store, access, clock), Dispatcher{Outbox: store, Subscriber: subscriber}, nil
}
