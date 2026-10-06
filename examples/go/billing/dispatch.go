package billing

import (
	"context"

	"example.com/architecture/invoice/billing/contracts"
)

type Outbox interface {
	Pending(context.Context) ([]contracts.InvoiceVoided, error)
	Acknowledge(context.Context, string) error
}

// Subscriber receives Billing's published events. Billing does not name its subscribers.
type Subscriber interface {
	Deliver(context.Context, contracts.InvoiceVoided) error
}

// Dispatcher retries unacknowledged deliveries. Delivery is at least once.
type Dispatcher struct {
	Outbox     Outbox
	Subscriber Subscriber
}

func (d Dispatcher) Dispatch(ctx context.Context) error {
	events, err := d.Outbox.Pending(ctx)
	if err != nil {
		return err
	}
	for _, event := range events {
		if err := d.Subscriber.Deliver(ctx, event); err != nil {
			return err
		}
		if err := d.Outbox.Acknowledge(ctx, event.ID); err != nil {
			return err
		}
	}
	return nil
}
