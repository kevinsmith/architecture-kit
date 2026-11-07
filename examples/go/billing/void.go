package billing

import (
	"context"
	"errors"
	"fmt"
	"time"

	"example.com/architecture/invoice/billing/contracts"
	"example.com/architecture/invoice/billing/internal/domain"
	"example.com/architecture/invoice/billing/internal/ports"
)

// Rejections are exposed here so callers need not import private Domain code.
// Operational errors remain distinct and retain their original error chain.
var (
	ErrNotFound      = errors.New("invoice not found")
	ErrForbidden     = errors.New("invoice access denied")
	ErrConflict      = errors.New("invoice changed concurrently")
	ErrAlreadyVoided = domain.ErrAlreadyVoided
	ErrSettled       = domain.ErrSettled
	ErrExpired       = domain.ErrExpired
	ErrInvalidState  = domain.ErrInvalidState
)

type Clock interface {
	Now() time.Time
}

// Access is consumer-owned. Its provider does not import Billing.
type Access interface {
	Can(ctx context.Context, actor, permission, organization string) (bool, error)
}

type Command struct {
	InvoiceID      string
	OrganizationID string
}

type Service struct {
	store  ports.Store
	access Access
	clock  Clock
}

func newService(store ports.Store, access Access, clock Clock) Service {
	return Service{store: store, access: access, clock: clock}
}

// actor is trusted invocation context established by a driving adapter.
func (s Service) Void(ctx context.Context, actor string, command Command) error {
	invoice, found, err := s.store.Load(ctx, command.InvoiceID)
	if err != nil {
		return fmt.Errorf("load invoice: %w", err)
	}
	if !found {
		return ErrNotFound
	}
	if actor == "" || command.OrganizationID != invoice.OrganizationID {
		return ErrForbidden
	}
	allowed, err := s.access.Can(ctx, actor, contracts.VoidInvoice, invoice.OrganizationID)
	if err != nil {
		return fmt.Errorf("authorize invoice: %w", err)
	}
	if !allowed {
		return ErrForbidden
	}
	now := s.clock.Now()
	next, err := domain.Void(invoice, now)
	if err != nil {
		return err
	}
	event := contracts.InvoiceVoided{
		ID:        fmt.Sprintf("%s:void:%d", invoice.ID, next.Version),
		InvoiceID: invoice.ID, OrganizationID: invoice.OrganizationID,
		OccurredAt: now.UnixNano(), Recipients: []contracts.Recipient{{Address: invoice.Contact}},
	}
	return s.store.Run(ctx, func(tx ports.Transaction) error {
		saved, err := tx.Save(ctx, invoice, next)
		if err != nil {
			return fmt.Errorf("save invoice: %w", err)
		}
		if !saved {
			return ErrConflict
		}
		if err := tx.AddEvent(ctx, event); err != nil {
			return fmt.Errorf("record invoice event: %w", err)
		}
		return nil
	})
}
