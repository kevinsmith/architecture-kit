package access

import (
	"context"
	"database/sql"

	"example.com/architecture/invoice/access/internal/sqlite"
)

// Open assembles Access over a configured database. It is composition, kept in
// Access's tree because only this tree can import Access's internal adapters.
func Open(ctx context.Context, db *sql.DB) (Service, error) {
	grants := sqlite.New(db)
	if err := grants.CreateSchema(ctx); err != nil {
		return Service{}, err
	}
	return Service{Grants: grants}, nil
}
