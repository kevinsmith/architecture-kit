package notifications

import (
	"context"

	"example.com/architecture/invoice/billing/contracts"
)

type Message struct {
	EventID   string
	Recipient string
	Body      string
}

type Mailer interface {
	Send(context.Context, Message) error
}

type Service struct{ Mailer Mailer }

func (s Service) Deliver(ctx context.Context, event contracts.InvoiceVoided) error {
	if err := event.Validate(); err != nil {
		return err
	}
	for _, recipient := range event.Recipients {
		if err := s.Mailer.Send(ctx, Message{
			EventID: event.ID, Recipient: recipient.Address, Body: "Invoice " + event.InvoiceID + " was voided.",
		}); err != nil {
			return err
		}
	}
	return nil
}
