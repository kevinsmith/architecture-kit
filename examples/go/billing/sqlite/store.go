package sqlite

import (
	"context"
	"database/sql"
	"database/sql/driver"
	"encoding/json"
	"errors"
	"time"

	"example.com/architecture/invoice/billing"
	"example.com/architecture/invoice/billing/contracts"
	"example.com/architecture/invoice/billing/domain"
)

type Store struct{ db *sql.DB }

func New(db *sql.DB) Store { return Store{db: db} }

func (s Store) CreateSchema(ctx context.Context) error {
	_, err := s.db.ExecContext(ctx, `
CREATE TABLE IF NOT EXISTS billing_invoices (
 id TEXT PRIMARY KEY, organization_id TEXT NOT NULL, status TEXT NOT NULL,
 deadline INTEGER NOT NULL, version INTEGER NOT NULL, contact TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS billing_outbox (
 id TEXT PRIMARY KEY, invoice_id TEXT NOT NULL REFERENCES billing_invoices(id),
 payload TEXT NOT NULL, delivered INTEGER NOT NULL DEFAULT 0
);`)
	return err
}

func (s Store) Load(ctx context.Context, id string) (domain.Invoice, bool, error) {
	var invoice domain.Invoice
	var deadline int64
	err := s.db.QueryRowContext(ctx, `SELECT id, organization_id, status, deadline, version, contact
FROM billing_invoices WHERE id = ?`, id).Scan(
		&invoice.ID, &invoice.OrganizationID, &invoice.Status, &deadline, &invoice.Version, &invoice.Contact)
	if errors.Is(err, sql.ErrNoRows) {
		return domain.Invoice{}, false, nil
	}
	if err != nil {
		return domain.Invoice{}, false, err
	}
	invoice.Deadline = time.Unix(0, deadline).UTC()
	return invoice, true, nil
}

func (s Store) Run(ctx context.Context, fn func(billing.Transaction) error) error {
	connection, err := s.db.Conn(ctx)
	if err != nil {
		return err
	}
	defer connection.Close()
	tx, err := connection.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	if err := fn(transaction{tx}); err != nil {
		return err
	}
	if err := tx.Commit(); err != nil {
		// This driver can leave a transaction open after sql.Tx is marked done.
		// Discard the reserved connection so it cannot leak tentative writes.
		_ = connection.Raw(func(any) error { return driver.ErrBadConn })
		return err
	}
	return nil
}

type transaction struct{ tx *sql.Tx }

func (t transaction) Save(ctx context.Context, before, next domain.Invoice) (bool, error) {
	result, err := t.tx.ExecContext(ctx, `UPDATE billing_invoices SET status = ?, version = ?
WHERE id = ? AND organization_id = ? AND status = ? AND version = ?`,
		next.Status, next.Version, before.ID, before.OrganizationID, before.Status, before.Version)
	if err != nil {
		return false, err
	}
	count, err := result.RowsAffected()
	return count == 1, err
}

func (t transaction) AddEvent(ctx context.Context, event contracts.InvoiceVoided) error {
	if err := event.Validate(); err != nil {
		return err
	}
	payload, err := json.Marshal(event)
	if err != nil {
		return err
	}
	_, err = t.tx.ExecContext(ctx, `INSERT INTO billing_outbox (id, invoice_id, payload) VALUES (?, ?, ?)`, event.ID, event.InvoiceID, string(payload))
	return err
}

func (s Store) Pending(ctx context.Context) ([]contracts.InvoiceVoided, error) {
	rows, err := s.db.QueryContext(ctx, `SELECT payload FROM billing_outbox WHERE delivered = 0 ORDER BY id`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var events []contracts.InvoiceVoided
	for rows.Next() {
		var payload []byte
		if err := rows.Scan(&payload); err != nil {
			return nil, err
		}
		var event contracts.InvoiceVoided
		if err := json.Unmarshal(payload, &event); err != nil {
			return nil, err
		}
		if err := event.Validate(); err != nil {
			return nil, err
		}
		events = append(events, event)
	}
	return events, rows.Err()
}

func (s Store) Acknowledge(ctx context.Context, id string) error {
	_, err := s.db.ExecContext(ctx, `UPDATE billing_outbox SET delivered = 1 WHERE id = ?`, id)
	return err
}
