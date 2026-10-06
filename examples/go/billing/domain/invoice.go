package domain

import (
	"errors"
	"time"
)

type Status string

const (
	Open    Status = "open"
	Settled Status = "settled"
	Voided  Status = "voided"
)

type Invoice struct {
	ID             string
	OrganizationID string
	Status         Status
	Deadline       time.Time
	Version        int64
	Contact        string
}

var (
	ErrAlreadyVoided = errors.New("invoice already voided")
	ErrSettled       = errors.New("invoice settled")
	ErrExpired       = errors.New("invoice expired")
	ErrInvalidState  = errors.New("invalid invoice state")
)

func Void(invoice Invoice, now time.Time) (Invoice, error) {
	switch invoice.Status {
	case Voided:
		return invoice, ErrAlreadyVoided
	case Settled:
		return invoice, ErrSettled
	case Open:
	default:
		return invoice, ErrInvalidState
	}
	if !now.Before(invoice.Deadline) {
		return invoice, ErrExpired
	}
	invoice.Status = Voided
	invoice.Version++
	return invoice, nil
}
