// Package contracts publishes Billing's event data without exposing its implementation.
package contracts

import "errors"

const VoidInvoice = "invoice:void"

type Recipient struct {
	Address string `json:"address"`
}

// InvoiceVoided is read-only after publication. Each delivery owns its decoded slice.
type InvoiceVoided struct {
	ID             string `json:"id"`
	InvoiceID      string `json:"invoice_id"`
	OrganizationID string `json:"organization_id"`
	// OccurredAt is Unix nanoseconds in UTC, encoded as a JSON decimal string
	// to preserve precision in consumers whose JSON numbers use float64.
	OccurredAt int64       `json:"occurred_at,string"`
	Recipients []Recipient `json:"recipients"`
}

func (e InvoiceVoided) Validate() error {
	if e.ID == "" || e.InvoiceID == "" || e.OrganizationID == "" || e.OccurredAt <= 0 || len(e.Recipients) == 0 {
		return errors.New("incomplete invoice-voided event")
	}
	for _, recipient := range e.Recipients {
		if recipient.Address == "" {
			return errors.New("recipient address is required")
		}
	}
	return nil
}
