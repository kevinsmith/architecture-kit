// Package ports defines Billing's persistence ports. They name private Domain
// types, so they stay internal to Billing.
package ports

import (
	"context"

	"example.com/architecture/invoice/billing/contracts"
	"example.com/architecture/invoice/billing/internal/domain"
)

type Transaction interface {
	Save(ctx context.Context, before, next domain.Invoice) (bool, error)
	AddEvent(context.Context, contracts.InvoiceVoided) error
}

type Store interface {
	Load(context.Context, string) (domain.Invoice, bool, error)
	// Run commits on nil and rolls back on any error, including a rejection.
	// Nested units of work are not supported; use the supplied Transaction.
	Run(context.Context, func(Transaction) error) error
}
