package input

import (
	"bytes"
	"encoding/json"
	"errors"
	"io"

	"example.com/architecture/invoice/billing/contracts"
)

func Decode(data []byte) (contracts.InvoiceVoided, error) {
	var event contracts.InvoiceVoided
	decoder := json.NewDecoder(bytes.NewReader(data))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&event); err != nil {
		return event, err
	}
	if err := decoder.Decode(new(any)); err != io.EOF {
		return event, errors.New("expected exactly one event")
	}
	return event, event.Validate()
}
